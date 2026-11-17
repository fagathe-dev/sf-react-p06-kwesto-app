# Tests

| Application | Outil | Commande | Couverture |
| :--- | :--- | :--- | :--- |
| `forms_api` | PHPUnit (WebTestCase) | `php bin/phpunit` | cycle de vie complet d'une campagne, immutabilité, anti-doublon, clôture `ALL_ANSWERED`, statistiques, upload (MIME réel), archive, recherche, crons, droits, règles des 7 types |
| `forms_bo` | Vitest + Testing Library | `npm test` | modèle du builder (numérotation, déplacements), validation et progression du formulaire participant, parcours en étapes |
| `forms_front` | PHPUnit (WebTestCase) | `php bin/phpunit` | 7 pages (SEO, canonical, OpenGraph, JSON-LD, absence de Stimulus), sitemap, robots.txt, formulaire de contact |

## forms_api : base de test

Les tests utilisent une vraie base MySQL (index FULLTEXT) au nom suffixé `_test`. `.env.local` n'est pas chargé en environnement de test : fournissez `DATABASE_URL` dans `.env.test.local` (ignoré par git) ou dans l'environnement.

```bash
cd apps/forms_api
export DATABASE_URL="mysql://<utilisateur>:<mot-de-passe>@127.0.0.1:3306/kwesto?serverVersion=8.4.3&charset=utf8mb4"
APP_ENV=test php bin/console doctrine:database:create --if-not-exists
APP_ENV=test php bin/console doctrine:migrations:migrate -n
APP_ENV=test php bin/console lexik:jwt:generate-keypair --skip-if-exists   # clés config/jwt/test_*.pem
php bin/phpunit
```

Les tests créent leurs propres comptes (adresses aléatoires) : ils cohabitent avec les fixtures. `UPLOAD_STORAGE_DIR` pointe vers `var/uploads_test`.

## forms_bo : piège Windows

Vitest échoue avec « Vitest failed to find the current suite » quand la casse de la lettre de lecteur diffère (`d:\…` dans Git Bash contre `D:\…`) : le module est chargé deux fois. Lancez les tests depuis un chemin en majuscule, par exemple dans PowerShell :

```powershell
Set-Location D:\workspace\sf-react-p06-kwesto-app\apps\forms_bo
npm test
```

Les tests utilisent `vitest.config.ts` (sans le compilateur React, réservé au build de `vite.config.ts`).

## Vérifications statiques

```bash
# forms_api et forms_front
php bin/console lint:container
php bin/console doctrine:schema:validate          # forms_api, base migrée
php bin/console lint:twig templates               # forms_front

# forms_bo
npm run lint && npx tsc -b --noEmit

# forms_front (assets)
npm run build
npx tsc --noEmit --ignoreDeprecations 6.0
```
