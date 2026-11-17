# kwesto

Plateforme de questionnaires et de sondages **sur invitation nominative** : conception par glisser-déposer, diffusion par lien personnel à usage unique, relances et clôture automatiques, statistiques consolidées.

| Profil | Accès | Capacités |
| :--- | :--- | :--- |
| Créateur (`ROLE_USER`) | dashboard | concevoir, valider, diffuser, suivre, analyser, exporter |
| Administrateur (`ROLE_ADMIN`) | dashboard | + aperçu des templates d'e-mails, consultation des journaux |
| Participant | lien `/s/{token}` reçu par e-mail | une seule réponse, sans compte |

## Monorepo

| Dossier | Stack | Rôle |
| :--- | :--- | :--- |
| [`packages/forms-core`](packages/forms-core) | Bundle Symfony (PHP 8.4) | cœur métier partagé : entités, contrats JSON, services, e-mails |
| [`apps/forms_api`](apps/forms_api) | Symfony 8, LexikJWT, Messenger, Scheduler | API REST `/api/admin/*` et `/api/public/*`, crons, fixtures |
| [`apps/forms_bo`](apps/forms_bo) | React 19, Vite, TypeScript, core-react | dashboard créateur, Administration, formulaire participant |
| [`apps/forms_front`](apps/forms_front) | Symfony 8, Twig, SCSS, TypeScript, core-ts | site vitrine SEO, formulaire de contact |

Librairies internes : [core-php](https://github.com/fagathe-dev/core-php) (mailer, logger, upload, commandes), [core-ts](https://github.com/fagathe-dev/core-ts) (utilitaires TypeScript), [core-react](https://github.com/fagathe-dev/core-react) (UI Kit). Pas de `package.json` racine ni d'outil de monorepo.

## Démarrage rapide

Prérequis : PHP 8.4, Composer, MySQL 8 / MariaDB, Node.js 24. Détails et options : [docs/installation.md](docs/installation.md).

```bash
# 1. API — secrets dans apps/forms_api/.env.local (DATABASE_URL, APP_SECRET, JWT_PASSPHRASE, MAILER_DSN)
cd apps/forms_api
composer install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console lexik:jwt:generate-keypair --skip-if-exists
php bin/console doctrine:fixtures:load -n            # jeu de données de démonstration
symfony serve --port=8000                             # ou : php -S 127.0.0.1:8000 -t public
php bin/console messenger:consume async scheduler_default -vv   # invitations + tâches planifiées

# 2. Back-office
cd apps/forms_bo
npm install
npm run dev                                           # http://localhost:5173

# 3. Site vitrine
cd apps/forms_front
composer install && npm install && npm run build
symfony serve --port=8001                             # http://localhost:8001
```

Connexion : `camille@kwesto.test` / `Kwesto!2026` (créateur) ou `admin@kwesto.test` / `Kwesto!2026` (administrateur). Formulaire participant de démonstration : http://localhost:5173/s/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa — voir [docs/fixtures.md](docs/fixtures.md).

## Commandes utiles (`apps/forms_api`)

| Commande | Rôle |
| :--- | :--- |
| `php bin/console core-php:create-admin-user` | crée un administrateur (commande de core-php) |
| `php bin/console app:user:create <email> <nom> [--admin]` | crée un compte créateur |
| `php bin/console doctrine:fixtures:load -n` | recharge le jeu de démonstration |
| `php bin/console app:cron:run <tâche>` | exécute une tâche planifiée (`list` pour les voir) |
| `php bin/phpunit` | tests (base MySQL `_test`, voir [docs/tests.md](docs/tests.md)) |

## Documentation

| | |
| :--- | :--- |
| [Architecture](docs/architecture.md) | topologie, flux d'une campagne, machine à états |
| [Installation](docs/installation.md) | variables d'environnement, base, clés JWT, production |
| [Fixtures](docs/fixtures.md) | comptes, scénarios, liens participants, création d'admin |
| [forms-core](docs/forms-core.md) | entités, contrats JSON, services, extension |
| [API REST](docs/api.md) | endpoints, authentification, erreurs |
| [Back-office](docs/forms-bo.md) | builder, formulaire participant, conventions |
| [Site vitrine](docs/forms-front.md) | pages, SEO, chaîne esbuild + sass |
| [E-mails](docs/emails.md) | catalogue, aperçu, ajouter un e-mail |
| [Tâches planifiées](docs/scheduler.md) | crons, workers |
| [Tests](docs/tests.md) | suites et pièges connus |

Spécifications de référence : [`specs/specs_fonc.prompt.md`](specs/specs_fonc.prompt.md) et [`specs/specs_tech.prompt.md`](specs/specs_tech.prompt.md). Consignes pour les agents IA : [`SKILL.md`](SKILL.md).
