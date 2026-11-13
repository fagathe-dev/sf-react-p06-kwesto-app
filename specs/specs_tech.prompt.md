# Spécifications Techniques — Monorepo Symfony 8 / PHP 8.4, React TypeScript & Site vitrine

> **Contexte d'utilisation :** architecture logicielle du monorepo, contrats de données, intégration des librairies internes (`core-php`, `core-ts`, `core-react`), package partagé `forms-core`, endpoints REST et tâches planifiées.
> Document compagnon : [`specs_fonc.prompt.md`](./specs_fonc.prompt.md).

> ⚠️ **Document incomplet.** La source initiale de ce document était tronquée au milieu du tableau de l'ancienne section 2 (ligne `core-php`). Les sections de détail (contrats de données JSON, endpoints REST, schéma Doctrine, Scheduler, etc.) sont à réinjecter depuis la version complète : voir la section 9.

## Table des matières

1. [Topologie du monorepo](#1-topologie-du-monorepo)
2. [Répartition des responsabilités entre les 3 applications](#2-répartition-des-responsabilités-entre-les-3-applications)
3. [Package partagé `packages/forms-core`](#3-package-partagé-packagesforms-core) (dont recherche plein texte, §3.5)
4. [Intégration des 3 librairies internes](#4-intégration-des-3-librairies-internes)
5. [Site vitrine `apps/forms_front`](#5-site-vitrine-appsforms_front)
6. [Domaines et politique d'indexation](#6-domaines-et-politique-dindexation)
7. [Règles d'organisation du monorepo](#7-règles-dorganisation-du-monorepo)
8. [Variables d'environnement](#8-variables-denvironnement)
9. [Sections à compléter](#9-sections-à-compléter)

---

## 1. Topologie du monorepo

Monorepo **maison** : aucun outil externe (Lerna, Turborepo, Nx…), **aucun `package.json` racine**, aucun workspace npm. Chaque application est autonome ; la factorisation PHP passe par un package Composer local (`path` + lien symbolique).

```text
.
├── SKILL.md                        # Instructions et invariants pour les agents IA (reflète cette topologie)
├── specs/
│   ├── specs_fonc.prompt.md        # Spécifications fonctionnelles (incluant le site vitrine)
│   └── specs_tech.prompt.md        # Spécifications techniques (ce fichier)
├── apps/
│   ├── forms_front/                # Site vitrine SEO (Symfony 8 + Twig + SCSS + TypeScript)
│   │   ├── .npmrc                  # Contient : allow-git=root
│   │   ├── assets/                 # Sources SCSS et TypeScript (utilisant core-ts)
│   │   ├── composer.json           # Dépend de forms-core (path) et fagathe-dev/core-php
│   │   ├── package.json            # Dépend de core-ts (github:fagathe-dev/core-ts)
│   │   └── templates/              # Gabarits Twig SSR optimisés SEO
│   ├── forms_bo/                   # Back-Office métier (React + Vite + TypeScript)
│   │   ├── .npmrc                  # Contient : allow-git=root
│   │   ├── package.json            # Dépend de core-react et core-ts
│   │   └── src/                    # Dashboard créateur, Admin (logs/e-mails) et vue /s/:token
│   └── forms_api/                  # API REST pure (Symfony 8 + LexikJWTAuthenticationBundle)
│       ├── composer.json           # Dépend de forms-core (path) et fagathe-dev/core-php
│       └── src/                    # Contrôleurs REST (/api/admin, /api/public), Voters, Scheduler
└── packages/
    └── forms-core/                 # Package PHP/Symfony partagé (PSR-4 : FormsCore\)
        ├── composer.json           # Déclare le package local et dépend de fagathe-dev/core-php
        └── src/
            ├── DependencyInjection/
            ├── DTO/                # Contrats polymorphes (QuestionConfig, AnswerPayload, etc.)
            ├── Entity/             # Entités Doctrine (User, Survey, SurveySection, Question, SurveyRecipient, SurveyAnswer, UploadedAsset)
            ├── Enum/               # Enums PHP 8.4 (SurveyStatus, QuestionType, RecipientStatus, etc.)
            ├── Repository/         # Repositories Doctrine partagés
            ├── Service/            # Services métier transverses (Validation, Mailer, Telemetry, Stats)
            └── FormsCoreBundle.php # Bundle Symfony exposant le mapping Doctrine et l'autowiring
```

> **Changement par rapport à l'ancienne topologie :** `api/` devient `apps/forms_api/`, `frontend/` devient `apps/forms_bo/`, et deux éléments sont ajoutés : `apps/forms_front/` (site vitrine) et `packages/forms-core/` (cœur PHP partagé).

### 1.1 Configuration obligatoire de `.npmrc`

Les deux applications Node (`apps/forms_bo/` et `apps/forms_front/`) contiennent un fichier `.npmrc` avec la ligne suivante, nécessaire pour résoudre les dépendances GitHub (`core-ts`, `core-react`) lors d'exécutions en tant que root (Docker, CI/CD) :

```ini
# npm refuse par défaut les dépendances git (erreur EALLOWGIT).
# "root" autorise uniquement celles déclarées dans package.json (core-ts, core-react).
allow-git=root
```

> L'option npm s'écrit `allow-git` (et non `git-allow`).

---

## 2. Répartition des responsabilités entre les 3 applications

| Application | Stack | Rôle exclusif |
| :--- | :--- | :--- |
| `apps/forms_front` | Symfony + Twig + SCSS + `core-ts` | Site vitrine public, landing pages SEO, formulaire de contact. Consomme `forms-core`. |
| `apps/forms_bo` | React + Vite + TypeScript (`core-react` + `core-ts`) | Dashboard créateur (builder drag-and-drop, diffusion, statistiques), espace Admin (logs, preview e-mails) et formulaire répondant (`/s/:token`). Communique **uniquement** avec `forms_api`. |
| `apps/forms_api` | Symfony + LexikJWT + Scheduler | API REST JSON (`/api/admin/*`, `/api/public/*`), authentification JWT, exécution des tâches cron, envoi des e-mails. Consomme `forms-core`. |

Règle de dépendance : `forms_front` et `forms_api` dépendent de `forms-core` ; `forms_bo` ne dépend d'aucun code PHP et n'échange avec le back qu'au travers de l'API REST.

---

## 3. Package partagé `packages/forms-core`

### 3.1 Principe de factorisation

Tout ce qui est commun aux applications Symfony doit résider dans `packages/forms-core` : entités, repositories, enums, DTO, services de validation, helpers de mailer et de logger basés sur `fagathe-dev/core-php`. Aucune de ces briques ne doit être dupliquée dans `forms_api` ou `forms_front`.

| Dossier | Contenu |
| :--- | :--- |
| `DependencyInjection/` | Extension du bundle, configuration, autowiring |
| `DTO/` | Contrats polymorphes (`QuestionConfig`, `AnswerPayload`, …) |
| `Entity/` | `User`, `Survey`, `SurveySection`, `Question`, `SurveyRecipient`, `SurveyAnswer`, `UploadedAsset` |
| `Enum/` | Enums PHP 8.4 (`SurveyStatus`, `QuestionType`, `RecipientStatus`, …) |
| `Repository/` | Repositories Doctrine partagés |
| `Service/` | Services transverses : Validation, Mailer, Telemetry, Stats |
| `FormsCoreBundle.php` | Bundle Symfony exposant le mapping Doctrine et l'autowiring |

### 3.2 `packages/forms-core/composer.json`

Doit définir :
* `"name": "fagathe-dev/forms-core"`
* `"type": "symfony-bundle"`
* autoloading PSR-4 : `"FormsCore\\": "src/"`
* dépendances : `fagathe-dev/core-php` et `beberlei/doctrineextensions` (fonctions DQL pour la recherche plein texte, voir §3.5)

### 3.3 `composer.json` des applications Symfony

`apps/forms_api/composer.json` et `apps/forms_front/composer.json` déclarent le package local par un dépôt `path` avec lien symbolique, ainsi que le dépôt VCS de `core-php` :

```json
"repositories": [
  {
    "type": "path",
    "url": "../../packages/forms-core",
    "options": { "symlink": true }
  },
  {
    "type": "vcs",
    "url": "https://github.com/fagathe-dev/core-php.git"
  }
]
```

Les deux applications requièrent `fagathe-dev/forms-core` et `fagathe-dev/core-php`.

### 3.4 Mapping Doctrine

`FormsCoreBundle` (ou, à défaut, le `doctrine.yaml` de chacune des deux applications) doit configurer Doctrine ORM pour charger automatiquement les entités situées dans `packages/forms-core/src/Entity`.

### 3.5 Recherche plein texte (index FULLTEXT + extension DQL)

La recherche textuelle de l'API (`forms_api`) et du site vitrine (`forms_front`) repose sur des **index FULLTEXT** de la base de données, interrogés en DQL. La logique de recherche est **mutualisée dans `forms-core`** (repositories + service `Search`) et n'est jamais réécrite dans les apps.

> **Hypothèse :** base **MySQL / MariaDB** (InnoDB), qui fournit `MATCH … AGAINST`. Si la base est PostgreSQL, la stratégie change (`tsvector` + index GIN, autre extension DQL) : à confirmer.

**Bibliothèque**

| Élément | Choix |
| :--- | :--- |
| Extension DQL | `beberlei/doctrineextensions` (dépendance de `packages/forms-core/composer.json`) |
| Fonction DQL | `MATCH_AGAINST` → `DoctrineExtensions\Query\Mysql\MatchAgainst` |

**Enregistrement de la fonction DQL.** `FormsCoreBundle` préfixe la configuration Doctrine (`prependExtensionConfig`) afin que `forms_api` et `forms_front` en bénéficient sans configuration dupliquée :

```yaml
# équivalent de la configuration injectée par FormsCoreBundle
doctrine:
  orm:
    dql:
      string_functions:
        MATCH_AGAINST: DoctrineExtensions\Query\Mysql\MatchAgainst
```

**Déclaration des index.** Index FULLTEXT portés par les entités de `forms-core` (attribut `#[ORM\Index(columns: [...], flags: ['fulltext'])]`) et créés par migration Doctrine. Cibles prévues :

| Entité | Colonnes indexées | Usage |
| :--- | :--- | :--- |
| `Survey` | `title`, `description` | Recherche de questionnaires dans le dashboard |
| `Question` | `label`, `helpText` | Recherche dans la structure d'un questionnaire |
| `SurveyRecipient` | `email` | Recherche d'un destinataire dans le suivi |
| `SurveyAnswer` | `searchText` | Recherche par mot-clé dans les verbatims (questions `TEXT`) |

> Les réponses sont stockées dans un payload JSON polymorphe, **non indexable en FULLTEXT**. Les réponses de type `TEXT` doivent donc être recopiées, normalisées, dans une colonne dédiée `searchText` (type `TEXT`) de `SurveyAnswer`, qui porte l'index.

**Règles d'implémentation**
* Les requêtes utilisent `MATCH_AGAINST(alias.col1, alias.col2, :terme)` avec paramètre lié (jamais de concaténation de la saisie), en mode **naturel** ou **booléen** selon le besoin, et le score de pertinence sert au tri.
* La saisie utilisateur est normalisée (trim, longueur max, retrait des opérateurs booléens non voulus) avant d'être transmise.
* Limite InnoDB : les mots plus courts que `innodb_ft_min_token_size` (3 par défaut) et les mots vides (*stopwords*) sont ignorés ; prévoir un repli `LIKE` pour les termes très courts.
* Toute modification des colonnes indexées passe par une migration (création / suppression des index FULLTEXT).
* Les résultats sont paginés ; un endpoint de recherche expose le service `Search` côté `forms_api`, et `forms_front` l'utilise directement via `forms-core` s'il propose une recherche publique.

---

## 4. Intégration des 3 librairies internes

Aucune logique déjà couverte par ces trois dépôts ne doit être réécrite.

| Dépôt GitHub | Utilisé par | Responsabilités techniques |
| :--- | :--- | :--- |
| <https://github.com/fagathe-dev/core-php.git> | `forms-core`, `forms_api`, `forms_front` (Composer, dépôt VCS) | Composants de génération de templates d'e-mails et templates d'e-mails prédéfinis, mailer et logger. *(liste détaillée à compléter, source tronquée)* |
| `github:fagathe-dev/core-ts` | `forms_front`, `forms_bo` (npm) | Utilitaires TypeScript sans React. Dans `forms_front` : navigation mobile, accordéons FAQ, onglets de démonstration, presse-papiers, consentement. *(liste détaillée à compléter)* |
| `fagathe-dev/core-react` | `forms_bo` (npm) | Composants et hooks React du back-office. *(liste détaillée à compléter)* |

---

## 5. Site vitrine `apps/forms_front`

Application Symfony dédiée à l'acquisition organique (SEO) et à la présentation du produit. Description fonctionnelle : [`specs_fonc.prompt.md`, §11](./specs_fonc.prompt.md#11-site-vitrine-public).

### 5.1 Stack front

| Élément | Choix |
| :--- | :--- |
| Rendu | Symfony + Twig, HTML serveur (SSR) natif et instantané |
| CSS | SCSS (`sass`), architecture modulaire |
| JavaScript | TypeScript + `core-ts`, **sans React, sans Stimulus** : navigation mobile, accordéons FAQ, onglets de démonstration des types de questions, presse-papiers / consentement |
| npm | `.npmrc` contenant obligatoirement `allow-git=root` ; compilation esbuild + sass (§5.1.2) |
| PHP | Dépend de `forms-core` (path) et `fagathe-dev/core-php` |

### 5.1.1 Exclusions obligatoires (`forms_front`)

Les technologies suivantes sont **strictement interdites** dans `apps/forms_front`, sous toutes leurs formes :

| Interdit | Détail |
| :--- | :--- |
| **Symfony UX** | Aucun paquet `symfony/ux-*` (Turbo, Live Components, Twig Components UX, etc.), aucun bundle UX activé |
| **Stimulus** | Aucun paquet `@hotwired/stimulus`, `@symfony/stimulus-bundle` ni `symfony/stimulus-bundle` ; aucun contrôleur Stimulus, aucun attribut `data-controller` / `data-action` / `data-*-target` |
| **AssetMapper** | Aucun paquet `symfony/asset-mapper` ; pas d'`importmap.php`, pas de dossier `assets/controllers/`, pas de fichier `assets/bootstrap.js`, pas de fonctions Twig `importmap()` / `asset_mapper` |

Conséquences :
* `composer.json` de `forms_front` ne doit contenir aucune de ces dépendances, y compris en `require-dev`. Les recettes Flex correspondantes ne doivent pas être appliquées (ne pas laisser `symfony/flex` les réinstaller) ; vérifier `config/bundles.php` et `config/packages/` (aucun `asset_mapper.yaml`, `stimulus.yaml`, `ux_*.yaml`).
* Les interactions JavaScript (voir §5.1) sont écrites en TypeScript avec `core-ts` uniquement, sans framework de contrôleurs.
* Les assets SCSS / TypeScript sont compilés par la chaîne npm propre à l'application (esbuild + sass, voir §5.1.2), et le résultat compilé est référencé depuis Twig comme fichiers statiques (`public/css/`, `public/js/`) ; Symfony ne gère pas le pipeline d'assets.
* Cette règle ne s'applique pas à `forms_api` ni à `forms_bo`, qui n'embarquent de toute façon aucun de ces éléments.

### 5.1.2 Chaîne de compilation des assets (TypeScript & SCSS)

La configuration est **reprise du dépôt de référence** <https://github.com/fagathe-dev/sf-p05-design-system> (commit `e527042`), à l'exception de tout ce qui touche à Stimulus / Turbo / Symfony UX (voir §5.1.1).

| Fichier de référence | Reprise dans `apps/forms_front/` |
| :--- | :--- |
| `scripts/compile-ts.js` | Identique |
| `scripts/compile-scss.js` | Identique (voir « Points à trancher ») |
| `package.json` | Repris **sans** `@hotwired/stimulus`, `@hotwired/turbo`, `@symfony/ux-turbo` |
| `.npmrc` | Identique (`allow-git=root`) |
| `.prettierrc` | Identique |
| `tsconfig.json` | Identique |

**Arborescence et sorties**

| Source | Sortie | Outil |
| :--- | :--- | :--- |
| `assets/ts/main.ts` (chargé sur toutes les pages) et `assets/ts/pages/*.ts` (un script par page) | `public/js/` ; code partagé (dont `core-ts`) extrait dans `public/js/chunks/` | esbuild |
| `assets/scss/ds-*.scss` (un point d'entrée par fichier) | `public/css/ds-*.css` | sass |

Les autres modules TypeScript (`components/`, `interactions/`) sont importés par les points d'entrée ci-dessus.

**`scripts/compile-ts.js` (esbuild)**
* Bundle ESM, `splitting: true`, `chunkNames: 'chunks/[name]-[hash]'`, cible `es2020`, plateforme navigateur, `tsconfig.json` de l'app.
* Dev : source maps. Build (`--mode build`) : minifié, `legalComments: none`, `debugger` supprimés, code retour 1 en cas d'échec.
* `--watch` : rebuild incrémental ; nettoyage des fichiers obsolètes de `public/js` (anciens chunks hachés, anciennes maps) après chaque build.

**`scripts/compile-scss.js` (sass)**
* Dev : CSS `expanded` + source maps. Build : CSS `compressed`, sans source map (les maps obsolètes sont supprimées), code retour 1 en cas d'échec.
* `--watch` : ne recompile que les points d'entrée dont une dépendance a changé (debounce 100 ms) ; un nouveau `ds-*.scss` est pris en compte sans redémarrage.
* Étape annexe `compileIcons` : préfixe, dans `public/assets/ds-icons.css`, les URLs de polices / images par `DEFAULT_URI` (lue dans `.env`, `.env.local` ou l'environnement).

**`package.json`**

```json
{
  "scripts": {
    "build:ts": "node scripts/compile-ts.js --mode build",
    "build:scss": "node scripts/compile-scss.js --mode build",
    "build": "concurrently --kill-others-on-fail -n ts,scss -c blue,magenta \"npm:build:ts\" \"npm:build:scss\"",
    "compile:ts": "node scripts/compile-ts.js",
    "compile:scss": "node scripts/compile-scss.js",
    "compile": "concurrently --kill-others-on-fail -n ts,scss -c blue,magenta \"npm:compile:ts\" \"npm:compile:scss\"",
    "dev:ts": "node scripts/compile-ts.js --watch",
    "dev:scss": "node scripts/compile-scss.js --watch",
    "dev": "concurrently -n ts,scss -c blue,magenta \"npm:dev:ts\" \"npm:dev:scss\""
  },
  "devDependencies": {
    "concurrently": "^9.2.1",
    "esbuild": "^0.28.0",
    "sass": "^1.99.0",
    "typescript": "^6.0.3"
  },
  "dependencies": {
    "core-ts": "github:fagathe-dev/core-ts#master"
  },
  "allowScripts": {
    "esbuild": true,
    "@parcel/watcher": false
  }
}
```

**`.prettierrc`**

```json
{
  "semi": true,
  "singleQuote": true,
  "tabWidth": 2
}
```

**`tsconfig.json`**

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ESNext",
    "lib": ["ES2020", "DOM", "DOM.Iterable"],
    "moduleResolution": "node",
    "rootDir": "assets/ts",
    "outDir": "public/js",
    "baseUrl": "./",
    "paths": {
      "@/*": ["assets/ts/*"],
      "@components/*": ["assets/ts/components/*"],
      "@data/*": ["data/*"]
    },
    "resolveJsonModule": true,
    "esModuleInterop": true,
    "allowSyntheticDefaultImports": true,
    "strict": true,
    "noImplicitAny": true,
    "strictNullChecks": true,
    "alwaysStrict": true,
    "skipLibCheck": true
  },
  "include": ["assets/ts/**/*"],
  "exclude": ["node_modules", "vendor", "var", "public", "tests"]
}
```

**Usage dans Twig :** les fichiers compilés sont référencés comme assets statiques (ex. `<link rel="stylesheet" href="{{ asset('css/ds-main.css') }}">`, `<script type="module" src="{{ asset('js/main.js') }}">`) avec la fonction `asset()` du composant `symfony/asset`, qui n'a rien à voir avec AssetMapper. Pas d'`importmap()`.

**Points à trancher**
* Le motif `ds-*.scss` et l'étape `compileIcons` / `DEFAULT_URI` sont propres au design system de référence. Ils sont repris tels quels ; à adapter ou retirer si `forms_front` n'embarque pas de `ds-icons.css`.

### 5.2 Routes des pages

| Route | Contenu |
| :--- | :--- |
| `/` | Accueil |
| `/fonctionnalites` | Fonctionnalités |
| `/cas-usage` | Pages dédiées par cas d'usage |
| `/tarifs` | Offres et FAQ |
| `/contact` | Formulaire de contact, traité via les services de `forms-core` et `core-php` |
| `/mentions-legales` | Page légale |
| `/confidentialite` | Page RGPD |
| `/sitemap.xml` | Route technique dynamique |
| `/robots.txt` | Route technique dynamique |

### 5.3 SEO technique

* **Gabarit maître** `templates/base.html.twig` avec blocs dédiés : `<title>`, meta description, `rel="canonical"`, balises OpenGraph / Twitter Cards, données structurées JSON-LD (`SoftwareApplication` et `FAQPage`).
* **`/sitemap.xml`** généré dynamiquement : toutes les pages publiques, avec `<lastmod>` et `<priority>`.
* **`/robots.txt`** généré dynamiquement.

---

## 6. Domaines et politique d'indexation

| Application | Domaine | Politique |
| :--- | :--- | :--- |
| `forms_front` | `domaine.fr` | `index, follow` |
| `forms_bo` | `app.domaine.fr` | Header HTTP `X-Robots-Tag: noindex, nofollow` sur **toutes** les routes |
| `forms_api` | `api.domaine.fr` | Header HTTP `X-Robots-Tag: noindex, nofollow` sur **toutes** les routes |

Les liens personnels des participants (`/s/{token}`) sont servis par `forms_bo` (`app.domaine.fr`).

---

## 7. Règles d'organisation du monorepo

* Pas de `package.json` à la racine ; chaque app Node gère ses propres dépendances.
* Toute logique PHP commune aux apps Symfony va dans `packages/forms-core` ; une logique utilisée par une seule app reste dans cette app.
* `forms_bo` ne parle qu'à `forms_api`.
* Dans `forms_bo`, le formulaire répondant inclut une barre de progression (toujours) et un stepper (si le questionnaire a ≥ 2 sections) : voir fonc §3.4 et §6.4. Le modèle expose `SurveySection` (`title`, `description`, `position`) et un `sectionId` sur chaque question.
* `forms_front` n'utilise ni Symfony UX, ni Stimulus, ni AssetMapper (voir §5.1.1) ; ses assets sont compilés avec esbuild et sass (voir §5.1.2).
* `SKILL.md` à la racine doit refléter cette topologie, les règles de factorisation et les `.npmrc`.

---

## 8. Variables d'environnement

### 8.1 Règles
* Les secrets et réglages locaux vivent dans `.env.local` (un par application : `apps/forms_api/`, `apps/forms_front/`, `apps/forms_bo/`). Ces fichiers sont **ignorés par git** et **ne doivent jamais être lus par un agent IA** (voir `SKILL.md`). Le propriétaire y saisit les identifiants et mots de passe.
* Les valeurs par défaut **non secrètes** vont dans `.env` (versionné).
* Cette section est la **seule source** pour connaître les variables attendues.
* Dans `forms_bo`, toute variable `VITE_*` est publique (incluse dans le bundle) : aucun secret.

### 8.2 `apps/forms_api`

| Variable | Rôle |
| :--- | :--- |
| `APP_ENV`, `APP_SECRET` | Environnement et secret Symfony |
| `DATABASE_URL` | Connexion base (MySQL / MariaDB) |
| `MAILER_DSN`, `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME` | Envoi des e-mails transactionnels |
| `MESSENGER_TRANSPORT_DSN` | File d'envoi asynchrone |
| `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE` | LexikJWTAuthenticationBundle |
| `DEFAULT_URI`, `APP_FRONT_URL`, `APP_BO_URL` | URLs de l'API, du site vitrine et du back-office (liens des e-mails, `/s/{token}`) |
| `CORS_ALLOW_ORIGIN` | Origines autorisées (`forms_bo`) |
| `TRUSTED_PROXIES` | Résolution de l'IP client derrière un reverse-proxy (télémétrie) |
| `UPLOAD_MAX_SIZE_MB` | **Taille maximale d'un fichier uploadé, en Mo, pour toute la plateforme** (voir §8.5) |
| `UPLOAD_STORAGE_DIR` | Répertoire de stockage des fichiers uploadés |

### 8.3 `apps/forms_front`

| Variable | Rôle |
| :--- | :--- |
| `APP_ENV`, `APP_SECRET`, `DATABASE_URL` | Socle Symfony et base |
| `MAILER_DSN`, `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME`, `CONTACT_RECIPIENT_EMAIL` | Formulaire de contact |
| `DEFAULT_URI` | URL publique du site (sitemap, canonical, OpenGraph, `compile-scss.js`) |
| `APP_BO_URL` | Cible des CTA vers l'application |
| `TRUSTED_PROXIES` | Reverse-proxy |

### 8.4 `apps/forms_bo`

| Variable | Rôle |
| :--- | :--- |
| `VITE_API_URL` | URL de base de `forms_api` |

### 8.5 Limite d'upload configurable

* **Source unique** : `UPLOAD_MAX_SIZE_MB` (entier, en Mo ; défaut `10`), lue par `forms-core` via un paramètre du conteneur.
* **Taille effective** d'une question `FILE` : `min(question.maxSizeMb, UPLOAD_MAX_SIZE_MB)`. Le serveur **plafonne** toute valeur supérieure à l'enregistrement de la question (le builder prévient le créateur, voir fonc §4.5) et **rejette** à l'upload tout fichier dépassant la taille effective.
* Cohérence PHP : `upload_max_filesize` et `post_max_size` doivent être **supérieurs ou égaux** à `UPLOAD_MAX_SIZE_MB` (et au serveur web / reverse-proxy en amont, ex. `client_max_body_size`).
* `forms_bo` ne duplique pas cette valeur dans une variable `VITE_*` : il la lit depuis un endpoint de configuration de `forms_api` (champ `uploadMaxSizeMb`), accessible au back-office et à la vue répondant, afin d'afficher la limite dans le builder et près de la zone de dépôt.

---

## 9. Sections à compléter

<!-- TODO : reprendre depuis la version complète de la spec technique : contrats JSON polymorphes (QuestionConfig / AnswerPayload), schéma Doctrine, endpoints REST /api/admin/* et /api/public/*, authentification JWT, Voters, Scheduler (crons de la spec fonctionnelle §9), envoi des e-mails, détail des responsabilités de core-php / core-ts / core-react. -->
