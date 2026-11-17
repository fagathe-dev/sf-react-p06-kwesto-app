# Installation et démarrage

## Prérequis

| Outil | Version testée |
| :--- | :--- |
| PHP | 8.4 (extensions `intl`, `mbstring`, `pdo_mysql`, `fileinfo`, `gd`, `openssl`, `zlib`) |
| Composer | 2.8 |
| MySQL / MariaDB | MySQL 8.4 (index FULLTEXT InnoDB obligatoires) |
| Node.js / npm | Node 24, npm 12 |
| Symfony CLI | facultatif (`symfony serve`) |

L'extension PHP `zip` n'est pas requise : les archives sont produites par `maennchen/zipstream-php`.

## 1. Dépendances

```bash
cd apps/forms_api   && composer install
cd apps/forms_front && composer install && npm install
cd apps/forms_bo    && npm install
```

`packages/forms-core` est installé par Composer via un lien symbolique (`vendor/fagathe-dev/forms-core`) : toute modification du package est visible immédiatement dans les deux applications Symfony.

Les applications Node déclarent `allow-git=root` dans leur `.npmrc` : npm refuse sinon les dépendances Git (`core-ts`, `core-react`).

## 2. Variables d'environnement

Les valeurs par défaut non secrètes sont dans les `.env` versionnés. Les secrets se mettent dans un `.env.local` par application (ignoré par git). Référence complète : `specs/specs_tech.prompt.md` §8.

**`apps/forms_api/.env.local`** — à définir au minimum :

```dotenv
APP_SECRET=<chaîne aléatoire>
DATABASE_URL="mysql://<utilisateur>:<mot-de-passe>@127.0.0.1:3306/kwesto?serverVersion=8.4.3&charset=utf8mb4"
JWT_PASSPHRASE=<phrase secrète des clés JWT>
MAILER_DSN=smtp://<utilisateur>:<mot-de-passe>@<serveur>:465   # null://null pour ne rien envoyer
```

**`apps/forms_front/.env.local`** : `APP_SECRET`, `DATABASE_URL` (même base), `MAILER_DSN`, `CONTACT_RECIPIENT_EMAIL`.

**`apps/forms_bo/.env.local`** (facultatif) : `VITE_API_URL` si l'API n'écoute pas sur `http://localhost:8000`. Les variables `VITE_*` sont publiques : aucun secret.

> MySQL 8.4 ne charge plus `mysql_native_password` par défaut. Un compte créé avec ce plugin échoue (`Plugin 'mysql_native_password' is not loaded`) : recréez-le avec `caching_sha2_password`.

La limite d'upload `UPLOAD_MAX_SIZE_MB` (10 Mo par défaut) doit rester inférieure ou égale à `upload_max_filesize` et `post_max_size` de PHP, et à la limite du serveur web (`client_max_body_size`…).

## 3. Base de données et clés JWT (forms_api)

```bash
cd apps/forms_api
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate -n
php bin/console lexik:jwt:generate-keypair --skip-if-exists
```

Sous Windows, si la génération des clés échoue avec `error:80000003:system library::No such process`, indiquez le fichier de configuration OpenSSL de PHP :

```powershell
$env:OPENSSL_CONF = "C:\laragon\bin\php\<version>\extras\ssl\openssl.cnf"
php bin/console lexik:jwt:generate-keypair --skip-if-exists
```

## 4. Données de démonstration

```bash
php bin/console doctrine:fixtures:load -n
```

Comptes, scénarios et liens participants : voir [fixtures.md](fixtures.md). Pour un administrateur sans fixtures : `php bin/console core-php:create-admin-user`.

## 5. Démarrage

| Processus | Commande (depuis le dossier de l'application) | URL |
| :--- | :--- | :--- |
| API | `symfony serve --port=8000` ou `php -S 127.0.0.1:8000 -t public` | http://localhost:8000/api/health-check |
| Worker des invitations | `php bin/console messenger:consume async -vv` | — |
| Tâches planifiées | `php bin/console messenger:consume scheduler_default -vv` | — |
| Back-office | `npm run dev` | http://localhost:5173 |
| Site vitrine (assets) | `npm run dev` (recompilation continue) | — |
| Site vitrine | `symfony serve --port=8001` ou `php -S 127.0.0.1:8001 -t public` | http://localhost:8001 |

Sans worker `async`, les invitations restent `PENDING` : la tâche de rattrapage SMTP (toutes les 15 minutes) les envoie, ou lancez `php bin/console app:cron:run smtp-retry`.

## 6. Mise en production (points d'attention)

- `composer install --no-dev -o`, `APP_ENV=prod`, `composer dump-env prod`.
- `forms_front` : `npm run build` (CSS et JS minifiés dans `public/css`, `public/js`).
- `forms_bo` : `npm run build`, puis servir `dist/` avec un repli de toutes les routes vers `index.html` et l'en-tête `X-Robots-Tag: noindex, nofollow`.
- Superviser les deux workers (`async`, `scheduler_default`), par exemple avec systemd ou Supervisor.
- `TRUSTED_PROXIES` derrière un reverse-proxy, pour que la télémétrie enregistre l'IP réelle.
- `UPLOAD_STORAGE_DIR` sur un volume persistant, hors de `public/`.
