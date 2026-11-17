# Architecture

## Topologie

Monorepo maison : pas d'outil de monorepo, pas de `package.json` racine. Chaque application est autonome ; le code PHP commun vit dans un package Composer local, lié par un dépôt `path` avec lien symbolique.

```text
.
├── SKILL.md                    Instructions et invariants pour les agents IA
├── specs/                      Spécifications fonctionnelles et techniques (font foi)
├── docs/                       Cette documentation
├── apps/
│   ├── forms_api/              API REST Symfony 8 (JWT, Messenger, Scheduler, fixtures)
│   ├── forms_bo/               Back-office React 19 + Vite + TypeScript (core-react, core-ts)
│   └── forms_front/            Site vitrine Symfony 8 + Twig + SCSS + TypeScript (core-ts)
└── packages/
    └── forms-core/             Bundle PHP partagé (namespace FormsCore\)
```

| Application | Rôle | Dépend de |
| :--- | :--- | :--- |
| `packages/forms-core` | Entités, repositories, enums, contrats JSON, services métier, e-mails | `fagathe-dev/core-php`, `beberlei/doctrineextensions` |
| `apps/forms_api` | API JSON, authentification, envoi des e-mails, tâches planifiées | `forms-core`, `core-php` |
| `apps/forms_bo` | Dashboard créateur, espace Administration, formulaire `/s/:token` | `core-react`, `core-ts` — parle **uniquement** à `forms_api` |
| `apps/forms_front` | Pages marketing, SEO, formulaire de contact | `forms-core`, `core-php`, `core-ts` |

`forms_api` et `forms_front` partagent la même base MySQL ; seul `forms_api` porte les migrations.

## Ce qui vient des librairies internes

Rien de ce que couvrent `core-php`, `core-ts` et `core-react` n'est réécrit :

| Besoin | Brique utilisée |
| :--- | :--- |
| E-mails (validation du DTO, contrôle DNS, logs, layout et composants) | `core-php` : `AbstractMailerHandler`, `@CorePhp/layout.html.twig`, `@CorePhp/components/*` |
| Journaux JSON par fichier, rotation | `core-php` : `Logger`, `JsonLoggerRotation`, commande `core-php:log-file-rotation` |
| Validation MIME réelle et taille des fichiers | `core-php` : `UploaderValidationService`, `MimeType` |
| Navigateur, version, appareil | `core-php` : `DetectDevice` |
| Réponses de service `{ data, status, headers, context }` | `core-php` : `ResponseTrait` |
| Création d'un administrateur | `core-php` : commande `core-php:create-admin-user` (+ `App\Security\AdminUserManager`) |
| Client HTTP, `ApiError`, stockage local, dates, poids de fichiers | `core-ts` : `fetchAPI`, `StorageService`, `DateFormatter`, `FileSizeFormatter`, `router` |
| Presse-papiers, validation e-mail (site vitrine) | `core-ts` : `copyWithButtonFeedback`, `isEmail` |
| UI Kit, thème clair/sombre, icônes, hooks | `core-react` : `core-react/ui`, `core-react/theme`, `core-react/icons`, `useDebouncedValue` |

## Flux d'une campagne

```text
forms_bo (créateur)                 forms_api                           participant
──────────────────                  ─────────                           ───────────
Builder ──PUT structure──────────▶  SurveyService (DRAFT)
Valider ──POST validate──────────▶  DRAFT → VALIDATED (structure figée)
Destinataires ──POST recipients──▶  EmailListParser (nettoyage, dédoublonnage)
Lancer ──POST launch─────────────▶  jetons 64 hex, → ACTIVE, SendInvitationMessage ×N
                                    worker Messenger → SURVEY_INVITATION ─────────▶ e-mail
                                                                                    │
forms_bo /s/:token ◀─────────────────────────────────────────────────────────────────┘
  GET  /api/public/surveys/{token}  ParticipantService::open (OPENED)
  POST …/questions/{id}/files       AssetService (fichier orphelin)
  POST …/submit                     transaction + verrou : réponses, télémétrie, ANSWERED
                                    → SURVEY_RECEIPT, clôture ALL_ANSWERED éventuelle
Scheduler (worker) ────────────────▶ clôture à échéance, relance J-5/J-4, rattrapage SMTP,
                                    maintenance nocturne
Statistiques ◀──GET stats──────────  StatsService (temps réel, figées à la clôture)
```

## Machine à états

| État | Structure | Lien participant | Statistiques |
| :--- | :--- | :--- | :--- |
| `DRAFT` | modifiable (autosave du builder) | lien invalide | — |
| `VALIDATED` | figée | lien invalide (pas encore de jeton) | — |
| `ACTIVE` | figée | ouvert jusqu'à `expiresAt` | temps réel (instantané nocturne si volumineux) |
| `CLOSED` | figée | « questionnaire clôturé » | consolidées et figées (`statsSnapshot`) |

La clôture passe par `SurveyRepository::closeIfActive()` : une seule requête `UPDATE … WHERE status = 'ACTIVE'`, si bien que deux déclencheurs concurrents (dernière réponse et cron) ne notifient le créateur qu'une fois.

## Domaines et indexation

| Application | Domaine prévu | Indexation |
| :--- | :--- | :--- |
| `forms_front` | `domaine.fr` | `index, follow` |
| `forms_bo` | `app.domaine.fr` | `noindex, nofollow` (meta + en-tête à poser par le serveur web, voir [forms-bo.md](forms-bo.md)) |
| `forms_api` | `api.domaine.fr` | en-tête `X-Robots-Tag: noindex, nofollow` sur toutes les réponses (`NoIndexListener`) |
