# API REST (`apps/forms_api`)

Toutes les routes sont préfixées par `/api`, en JSON. Les contrôleurs sont minces : ils délèguent aux services de forms-core.

## Authentification

```http
POST /api/auth/login
Content-Type: application/json

{ "email": "camille@kwesto.test", "password": "S3dlc3RvITIwMjY=" }
```

**Mots de passe encodés en base64.** Tout mot de passe envoyé par un client est encodé en base64 (octets UTF-8 ; `encodePassword()` dans `forms_bo/src/utils/secret.ts`). Ici, `S3dlc3RvITIwMjY=` = `Kwesto!2026`. L'API le décode avant la vérification (`App\EventListener\Base64PasswordListener`, sur le firewall `login`). Une valeur qui n'est pas du base64 valide est refusée comme un mot de passe erroné (401). Toute nouvelle route qui reçoit un mot de passe suit la même règle. Le base64 n'est pas un chiffrement : la confidentialité repose sur HTTPS.

Réponse : `{ "token": "<JWT>", "user": { id, email, displayName, roles, createdAt } }`. Les requêtes suivantes portent `Authorization: Bearer <JWT>` (validité `JWT_TOKEN_TTL`, 8 h par défaut). 5 tentatives échouées par 15 minutes au maximum (`login_throttling`). La déconnexion consiste à oublier le jeton côté client.

| Préfixe | Accès |
| :--- | :--- |
| `/api/auth/login`, `/api/public/*`, `/api/health-check` | anonyme |
| `/api/admin/platform/*` | `ROLE_ADMIN` |
| tout le reste | `ROLE_USER` (créateur) ; un questionnaire n'est visible que de son créateur (`SurveyVoter`) |

Un questionnaire d'un autre créateur répond **404** (son existence n'est pas révélée).

## Configuration

| Méthode | Route | Description |
| :--- | :--- | :--- |
| GET | `/api/health-check` | État de santé : base (latence), dossiers d'écriture, limite d'upload ; 200 ou 503 |
| GET | `/api/auth/me` | Utilisateur connecté |
| GET | `/api/public/config` | `{ uploadMaxSizeMb, fileFormats }` — limite lue par le builder et le formulaire participant |

## Module 1 — conception

| Méthode | Route | Corps / paramètres | Notes |
| :--- | :--- | :--- | :--- |
| GET | `/api/admin/surveys` | `?page&status&q` | `{ items: [{ survey, recipients }], meta, counts }` |
| POST | `/api/admin/surveys` | `{ title, description? }` | crée un `DRAFT` |
| GET | `/api/admin/surveys/{id}` | — | `{ survey, recipients }` (groupe `survey.read`) |
| PATCH | `/api/admin/surveys/{id}` | `{ title?, description? }` | refusé (409) une fois la campagne lancée |
| PUT | `/api/admin/surveys/{id}/structure` | `{ sections: [], questions: [] }` | structure complète ; 409 hors `DRAFT` |
| POST | `/api/admin/surveys/{id}/validate` | — | 422 `{ message, errors: [{ scope, id, position, message }] }` si incomplet |
| POST | `/api/admin/surveys/{id}/duplicate` | — | nouveau `DRAFT` avec la même structure |
| DELETE | `/api/admin/surveys/{id}` | — | 409 si `ACTIVE` ; supprime aussi les fichiers |

Structure : l'ordre des tableaux fait foi. Chaque élément peut porter un `id` UUID généré par le client (conservé tant qu'il est libre) ; une question indique sa section par `sectionId`. La numérotation (`position`) est recalculée par le serveur, en continu sur tout le questionnaire.

```json
{
  "sections": [{ "id": "…", "title": "Votre profil", "description": null }],
  "questions": [{
    "id": "…", "sectionId": "…", "type": "NUMBER", "label": "Votre ancienneté",
    "helpTitle": "À savoir", "helpText": "En années.", "required": true,
    "config": { "allowDecimal": false, "min": 0, "max": 45, "unit": "ans" }
  }]
}
```

## Module 2 — diffusion

| Méthode | Route | Corps / paramètres | Notes |
| :--- | :--- | :--- | :--- |
| GET | `/api/admin/surveys/{id}/recipients` | `?page&status&q` | suivi paginé (50) + compteurs |
| POST | `/api/admin/surveys/{id}/recipients` | `{ emails }` (texte ou liste) ou multipart `file` (CSV, 2 Mo) | `VALIDATED` uniquement ; rapport `{ added, alreadyPresent, duplicates, invalid, overLimit }` |
| DELETE | `/api/admin/surveys/{id}/recipients` | `{ ids: [] }` ou `{ all: true }` | `VALIDATED` uniquement |
| POST | `/api/admin/surveys/{id}/launch` | `{ expiresAt: ISO 8601 }` | date strictement future ; jetons, `ACTIVE`, invitations asynchrones |
| POST | `/api/admin/surveys/{id}/close` | — | clôture manuelle (`MANUAL`) |

## Module 3 — formulaire participant (anonyme)

| Méthode | Route | Notes |
| :--- | :--- | :--- |
| GET | `/api/public/surveys/{token}` | `OPEN` : `{ state, survey, pendingAssets, draft, config }` (`draft` : `{ answers, questionId, savedAt }` ou `null`) ; sinon `{ state, message }` avec 404 (`INVALID`), 410 (`CLOSED`) ou 409 (`ANSWERED`) |
| POST | `/api/public/surveys/{token}/questions/{questionId}/files` | multipart `file` ; MIME réel, poids effectif, quota |
| DELETE | `/api/public/surveys/{token}/files/{assetId}` | retrait d'un fichier non encore envoyé |
| PUT | `/api/public/surveys/{token}/draft` | `{ answers, questionId? }` ; enregistre le brouillon (sans validation métier), `{ savedAt }` ; corps vide → brouillon effacé |
| DELETE | `/api/public/surveys/{token}/draft` | « Recommencer » : brouillon et fichiers en attente supprimés ; 204 |
| POST | `/api/public/surveys/{token}/submit` | `{ answers: { "<questionId>": valeur } }` ; 201, ou 400 `{ violations: { questionId: message } }` |

`{token}` : 64 caractères hexadécimaux. Les routes de brouillon renvoient 403 si le lien ne permet plus de répondre et ne sont pas limitées. Les routes de soumission et de dépôt sont limitées à 120 requêtes / 10 min par jeton et par IP. Les réponses portent `Cache-Control: no-store` et `Referrer-Policy: no-referrer`.

## Module 4 — statistiques et exports

| Méthode | Route | Notes |
| :--- | :--- | :--- |
| GET | `/api/admin/surveys/{id}/stats` | `?refresh=1` force le recalcul d'une campagne active ; `stats.frozen` à la clôture |
| GET | `/api/admin/surveys/{id}/stats/questions/{qid}/verbatims` | `?page&q` — question TEXT |
| GET | `/api/admin/surveys/{id}/stats/questions/{qid}/files` | `?page` — question FILE |
| GET | `/api/admin/surveys/{id}/export` | CSV (UTF-8 BOM, `;`), une ligne par destinataire, télémétrie incluse |
| GET | `/api/admin/surveys/{id}/archive` | .zip des fichiers : `Q01-intitule/adresse/fichier` |
| GET | `/api/admin/surveys/{id}/assets/{assetId}` | un fichier (`?inline=1` pour une image) ; CSP `sandbox`, `nosniff` |
| GET | `/api/admin/search` | `?q` (2 caractères min.) : `{ surveys, questions, recipients, verbatims }` |

## Espace Administration (`ROLE_ADMIN`)

| Méthode | Route | Notes |
| :--- | :--- | :--- |
| GET | `/api/admin/platform/emails` | aperçus disponibles, avec leur jeu de données |
| GET / POST | `/api/admin/platform/emails/{slug}` | `{ subject, html }` ; POST `{ data }` pour un jeu personnalisé |
| POST | `/api/admin/platform/emails/{slug}/test` | envoie l'aperçu à l'adresse de l'administrateur |
| GET | `/api/admin/platform/logs` | `?channel&level&from&to&q&page` (31 jours au maximum) |

## Erreurs

| Statut | Corps |
| :--- | :--- |
| 400 | `{ title, violations: { champ: message }, status }` (validation) ou `{ message }` (JSON invalide) |
| 401 | `{ code, message }` (Lexik) — jeton absent, invalide ou expiré |
| 403 / 404 / 409 / 410 / 422 / 429 | `{ message }` (+ `errors` pour 422) |
| 5xx | `{ message: "Une erreur interne est survenue." }` — détail journalisé dans `logs/app/` |

Toutes les réponses portent `X-Robots-Tag: noindex, nofollow`. CORS : origines de `CORS_ALLOW_ORIGIN`.
