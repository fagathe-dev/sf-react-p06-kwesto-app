# SKILL.md : sf-react-p06-kwesto-app (monorepo)

Instructions et invariants pour les agents IA. À lire avant toute intervention.

## 1. Règle absolue : fichiers secrets

**Ne JAMAIS lire, afficher, copier, rechercher dans, résumer ni modifier les fichiers `.env.local`** (`apps/*/.env.local` et tout fichier `.env.*.local`). Ils contiennent des identifiants et mots de passe saisis par le propriétaire.

- Pas de `Read`, `cat`, `grep`, `sed`, `head`, `tail`, `less`, `source`, `printenv`, ni de commande qui les afficherait indirectement (`env`, `docker compose config`, `symfony console debug:dotenv`, `debug:container --env-vars`, etc.).
- Ne jamais recopier une valeur d'environnement secrète dans le code, les specs, les logs, les messages de commit ou les réponses.
- Pour connaître les variables disponibles, se référer **uniquement** à `specs/specs_tech.prompt.md` (section « Variables d'environnement »). Le propriétaire gère les valeurs.
- Si une tâche semble exiger de lire ce fichier, s'arrêter et demander au propriétaire de fournir l'information utile (sans secret).
- Les valeurs non secrètes par défaut vivent dans `.env` (versionné) ; seuls les secrets et réglages locaux vont dans `.env.local`.

## 2. Topologie du monorepo

Monorepo maison, sans outil externe (pas de Lerna / Turborepo / Nx), **sans `package.json` racine**.

| Chemin                | Rôle                                                                                  |
| :-------------------- | :------------------------------------------------------------------------------------ |
| `apps/forms_front`    | Site vitrine SEO (Symfony 8 + Twig + SCSS + TypeScript/core-ts)                       |
| `apps/forms_bo`       | Back-office et formulaire répondant (React + Vite + TypeScript, core-react + core-ts) |
| `apps/forms_api`      | API REST (Symfony 8 + LexikJWT + Scheduler)                                           |
| `packages/forms-core` | Package PHP partagé (`FormsCore\`) : entités, repositories, enums, DTO, services      |
| `specs/`              | `specs_fonc.prompt.md` (fonctionnel), `specs_tech.prompt.md` (technique)              |

## 3. Invariants

- Les specs font foi. Ne pas altérer les règles métier, les 5 types de questions, les contrats JSON, les e-mails ni les crons sans demande explicite.
- Toute logique PHP commune à `forms_api` et `forms_front` va dans `packages/forms-core`. Rien ne doit être réécrit de ce que couvrent `core-php`, `core-ts`, `core-react`.
- `forms_bo` ne communique qu'avec `forms_api`.
- `.npmrc` des apps Node (`forms_bo`, `forms_front`) : `allow-git=root`.
- `forms_front` : **aucun** Symfony UX, Stimulus ni AssetMapper. Assets compilés avec esbuild + sass (config reprise de `sf-p05-design-system`).
- Recherche plein texte : index FULLTEXT + `beberlei/doctrineextensions` (`MATCH_AGAINST`), logique dans `forms-core`.
- Taille maximale d'upload : pilotée par `UPLOAD_MAX_SIZE_MB` (voir specs).
- Nom du produit : **kwesto**.
