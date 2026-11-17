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

- Les specs font foi. Ne pas altérer les règles métier, les **7 types de questions** (`TEXT`, `NUMBER`, `CHOICE`, `DATE`, `FILE`, `BOOLEAN`, `RANGE`), les contrats JSON, l'affichage conditionnel, les e-mails ni les crons sans demande explicite.
- **Questions conditionnelles** (`Question.displayCondition`, fonc §3.5, tech §9) :
  - une seule condition par question, sur une question source de position **strictement inférieure** (jamais `FILE`) ; invariant vérifié au builder (glisser-déposer), à l'enregistrement (`PUT /structure` → 422) et à la validation ;
  - supprimer une question source retire les conditions qui en dépendent ;
  - source sans réponse ou masquée ⇒ condition fausse (masquage en cascade) ;
  - une question masquée n'est jamais obligatoire et sa valeur est ignorée par l'API ;
  - évaluation unique côté serveur via `ConditionEvaluatorService` (soumission, statistiques, export), miroir `conditionEvaluator.ts` côté forms_bo ; le serveur reste seul juge ;
  - taux de réponse d'une question conditionnelle calculé sur les répondants éligibles ;
  - condition figée dès `VALIDATED`, comme le reste de la structure.
- **`BOOLEAN`** : presets `YES_NO` (défaut) / `TRUE_FALSE` / `CUSTOM`, libellés 1 à 80 caractères toujours éditables ; réponse toujours un booléen natif ; « sans réponse » ≠ `false`.
- Toute logique PHP commune à `forms_api` et `forms_front` va dans `packages/forms-core`. Rien ne doit être réécrit de ce que couvrent `core-php`, `core-ts`, `core-react`.
- `forms_bo` ne communique qu'avec `forms_api`.
- `.npmrc` des apps Node (`forms_bo`, `forms_front`) : `allow-git=root`.
- `forms_front` : **aucun** Symfony UX, Stimulus ni AssetMapper. Assets compilés avec esbuild + sass (config reprise de `sf-p05-design-system`).
- Recherche plein texte : index FULLTEXT + `beberlei/doctrineextensions` (`MATCH_AGAINST`), logique dans `forms-core`.
- Taille maximale d'upload : pilotée par `UPLOAD_MAX_SIZE_MB` (voir specs).
- Nom du produit : **kwesto**.

## 4. Conventions d'implémentation

Documentation détaillée : [`docs/`](docs/README.md). Points à respecter avant toute modification :

- **forms-core** (`packages/forms-core`, bundle `FormsCore\FormsCoreBundle`) déclare lui-même le mapping Doctrine, `MATCH_AGAINST`, les paramètres `forms_core.*` et l'autowiring : ne rien dupliquer dans les `doctrine.yaml` / `services.yaml` des apps.
- **Open/Closed** : un type de question = un `QuestionType\*Handler` (découvert par `QuestionTypeRegistry`, 6 handlers dont `BooleanHandler`) ; un e-mail = DTO + `Mailer\*MailHandler` + template + `Mailer\Preview\*MailPreview`. Aucun `switch` sur le type dans le code métier.
- Les services renvoient l'objet de `ResponseTrait` (core-php) ; les contrôleurs de `forms_api` restent minces (`RespondsWithServiceResult`). Aucun `EntityManager` dans les services : écritures via les repositories (`PersistenceTrait`).
- **Paramètres UUID en DQL** : toujours `->setParameter('x', $entity->getId(), UuidType::NAME)` (ou `ArrayParameterType::BINARY` pour une liste) ; une entité passée telle quelle n'est pas convertie en binaire et ne correspond à rien.
- Journaux applicatifs : `ChannelLoggerTrait` → `logs/<mailer|cron|app|security>/` (jamais de jeton, de mot de passe ni de contenu de DTO).
- Toute évolution du schéma passe par une migration de `forms_api` (seule app qui en porte).
- Fixtures : `apps/forms_api/src/DataFixtures` (fakerphp + orm-fixtures, graine fixe) ; jetons participants fixes documentés dans [`docs/fixtures.md`](docs/fixtures.md). Administrateur : `core-php:create-admin-user` (via `App\Security\AdminUserManager`).
- **forms_front** : CSRF de formulaire adossé à la session, `field_attr: []` (Symfony ajouterait sinon `data-controller="csrf-protection"`) ; canonical, sitemap et robots construits sur `DEFAULT_URI` ; `scripts/compile-*.js`, `.npmrc`, `.prettierrc`, `tsconfig.json` identiques au dépôt `sf-p05-design-system`.
- **forms_bo** : appels API uniquement dans `src/services/` (client `fetchAPI` de core-ts), UI exclusivement via `core-react`, rendu pur (règles ESLint du React Compiler). Tests Vitest à lancer depuis un chemin `D:\…` en majuscule sous Windows.
- Avant de rendre la main : `php bin/phpunit` (forms_api, forms_front), `npm run lint && npm test && npm run build` (forms_bo), `npm run build` (forms_front).
