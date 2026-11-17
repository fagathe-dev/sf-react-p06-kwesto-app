# Spécifications Techniques — Monorepo Symfony 8 / PHP 8.4, React TypeScript & Site vitrine

> **Contexte d'utilisation :** architecture logicielle du monorepo, contrats de données, intégration des librairies internes (`core-php`, `core-ts`, `core-react`), package partagé `forms-core`, endpoints REST et tâches planifiées.
> Document compagnon : [`specs_fonc.prompt.md`](./specs_fonc.prompt.md).

> ⚠️ **Document incomplet.** La source initiale de ce document était tronquée au milieu du tableau de l'ancienne section 2 (ligne `core-php`). Les sections de détail (contrats de données JSON, endpoints REST, schéma Doctrine, Scheduler, etc.) sont à réinjecter depuis la version complète : voir la section 10. La section 9 décrit déjà les contrats des questions (7 types et affichage conditionnel).

## Table des matières

1. [Topologie du monorepo](#1-topologie-du-monorepo)
2. [Répartition des responsabilités entre les 3 applications](#2-répartition-des-responsabilités-entre-les-3-applications)
3. [Package partagé `packages/forms-core`](#3-package-partagé-packagesforms-core) (dont recherche plein texte, §3.5)
4. [Intégration des 3 librairies internes](#4-intégration-des-3-librairies-internes)
5. [Site vitrine `apps/forms_front`](#5-site-vitrine-appsforms_front)
6. [Domaines et politique d'indexation](#6-domaines-et-politique-dindexation)
7. [Règles d'organisation du monorepo](#7-règles-dorganisation-du-monorepo)
8. [Variables d'environnement](#8-variables-denvironnement)
9. [Contrats des questions : 7 types et affichage conditionnel](#9-contrats-des-questions--7-types-et-affichage-conditionnel)
10. [Sections à compléter](#10-sections-à-compléter)

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
| `DTO/` | Contrats polymorphes (`QuestionConfig`, `AnswerPayload`, `DisplayCondition`, …) : voir §9 |
| `Entity/` | `User`, `Survey`, `SurveySection`, `Question`, `SurveyRecipient`, `SurveyAnswer`, `UploadedAsset` |
| `Enum/` | Enums PHP 8.4 (`SurveyStatus`, `QuestionType` — 7 types dont `BOOLEAN`, `RecipientStatus`, `ConditionOperator`, …) |
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
* Les conditions d'affichage (fonc §3.5) sont évaluées **par la même règle** côté serveur (`ConditionEvaluatorService`, forms-core) et côté navigateur (`conditionEvaluator.ts`, forms_bo) ; le serveur reste seul juge (voir §9.4).
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

## 9. Contrats des questions : 7 types et affichage conditionnel

Détail fonctionnel : fonc §3.5 (affichage conditionnel) et §4 (types). Les contrats ci-dessous sont communs à `packages/forms-core` (DTO PHP) et `apps/forms_bo` (types TypeScript, `src/types/survey.types.ts`).

### 9.1 Entité `Question` (forms-core)

| Champ | Colonne | Règle |
| :--- | :--- | :--- |
| `type` | `VARCHAR(16)`, enum `QuestionType` | `TEXT`, `NUMBER`, `CHOICE`, `DATE`, `FILE`, **`BOOLEAN`** |
| `config` | `JSON` | contrat `QuestionConfig` du type (§9.2) |
| `displayCondition` | **`JSON`, nullable** (nouveau) | contrat `QuestionDisplayCondition` (§9.3) ; `null` = toujours affichée |

* Base MySQL / MariaDB (hypothèse du §3.5) : colonne `JSON`. Une base PostgreSQL utiliserait `jsonb`.
* Ajout de la colonne et du case d'enum par **migration Doctrine** de `forms_api`.
* Le type est porté par `Question.type` : `config`, `displayCondition` et le payload de réponse ne répètent pas de discriminant `type`.

### 9.2 Contrats `QuestionConfig` et `AnswerPayload`

```typescript
type QuestionConfig =
  | { /* TEXT */    multiline: boolean; minLength?: number; maxLength?: number; placeholder?: string }
  | { /* NUMBER */  allowDecimal: boolean; min?: number; max?: number; step?: number; unit?: string }
  | { /* CHOICE */  widget: 'radio' | 'checkbox' | 'select'; multiple: boolean; options: { id: string; label: string }[] }
  | { /* DATE */    minDate?: string; maxDate?: string }                       // YYYY-MM-DD
  | { /* FILE */    allowedMimeTypes: string[]; maxSizeMb?: number; maxFiles: number }
  | { /* BOOLEAN */ preset: 'YES_NO' | 'TRUE_FALSE' | 'CUSTOM'; trueLabel: string; falseLabel: string; // 1 à 80 car.
      widget: 'buttons' | 'switch'; defaultValue: boolean | null }    // switch ⇒ defaultValue jamais null (false)
  | { /* RANGE */   min: number; max: number; step: number; values?: number[]; // values : échelle personnalisée triée (min/max déduits)
      minLabel?: string; maxLabel?: string; unit?: string; defaultValue: number | null };

type AnswerPayload =
  | { value: string }            // TEXT, DATE (YYYY-MM-DD)
  | { value: number }            // NUMBER, RANGE
  | { optionIds: string[] }      // CHOICE
  | { assetIds: string[] }       // FILE
  | { value: boolean };          // BOOLEAN — toujours un booléen natif
```

Côté PHP : `FormsCore\DTO\QuestionConfig\BooleanConfig` et `FormsCore\DTO\AnswerPayload\BooleanAnswer`, plus `FormsCore\QuestionType\BooleanHandler` (découvert par `QuestionTypeRegistry`, comme les autres) :

| Méthode du handler | Comportement `BOOLEAN` |
| :--- | :--- |
| `normalizeConfig` | preset par défaut `YES_NO` ; libellés manquants complétés depuis le preset ; preset recalculé en `CUSTOM` si les libellés diffèrent du modèle ; `widget` `buttons` par défaut ; `defaultValue` forcé à `false` pour un `switch` sans valeur |
| `validateConfig` | `trueLabel` et `falseLabel` obligatoires, 1 à 80 caractères, différents (sans tenir compte de la casse) |
| `isEmptyAnswer` | `null` ou chaîne vide uniquement (`false` est une réponse) |
| `validateAnswer` | accepte `true` / `false`, `"true"` / `"false"`, `1` / `0` ; tout le reste est rejeté |
| `computeStats` | `{ count, trueCount, falseCount, truePercent, falsePercent, trueLabel, falseLabel }` |
| `exportValue` | libellé correspondant à la valeur |

Presets (builder et `normalizeConfig`) : `YES_NO` → « Oui » / « Non » ; `TRUE_FALSE` → « Vrai » / « Faux ».

**RANGE** : `FormsCore\DTO\QuestionConfig\RangeConfig` et `FormsCore\QuestionType\RangeHandler` (réponse : `NumberAnswer`). `validateConfig` : `min < max`, pas > 0, `max - min` multiple du pas, 1000 crans au plus ; échelle personnalisée : au moins 2 valeurs ; `defaultValue` sur l'échelle. `validateAnswer` : valeur de l'échelle (bornes et cran, ou présente dans `values`). `computeStats` : `{ count, min, max, mean, median, scaleMin, scaleMax, minLabel, maxLabel, unit, distribution[{value, count}] (≤ 21 crans), histogram }`. Côté `forms_bo` : `ui/RangeSlider.tsx` (`Slider` de core-react), `builder/RangeEditor.tsx`.

### 9.3 Contrat `QuestionDisplayCondition`

```typescript
type ConditionOperator =
  | 'EQUALS'           // CHOICE à choix unique (option choisie), BOOLEAN, NUMBER, RANGE
  | 'NOT_EQUALS'
  | 'IN'               // CHOICE à choix unique : l'option choisie fait partie de la liste
  | 'LESS_THAN' | 'LESS_OR_EQUAL' | 'GREATER_THAN' | 'GREATER_OR_EQUAL'; // NUMBER, RANGE

interface QuestionDisplayCondition {
  sourceQuestionId: string;                    // UUID d'une question BOOLEAN, CHOICE à choix unique, NUMBER ou RANGE, de position inférieure
  operator: ConditionOperator;
  value: string | boolean | number | string[]; // optionId, liste d'optionId (IN), booléen ou nombre (fonc §3.5)
}
```

Exemples :

```json
{ "sourceQuestionId": "uuid-q1", "operator": "EQUALS", "value": "opt_femme" }
{ "sourceQuestionId": "uuid-q1", "operator": "IN", "value": ["opt_presse", "opt_autre"] }
{ "sourceQuestionId": "uuid-q1", "operator": "EQUALS", "value": true }
{ "sourceQuestionId": "uuid-q2", "operator": "LESS_THAN", "value": 5 }
```

**Sources autorisées** : `BOOLEAN`, `CHOICE` dont le widget est `radio` ou `select`, `NUMBER` et `RANGE`. `ConditionOperator::allowedFor($type, $multipleChoice)` renvoie une liste vide pour toute autre source (`TEXT`, `DATE`, `FILE`, `CHOICE` `checkbox`) ; `ConditionEvaluatorService::canBeSource()` et, côté navigateur, `canBeConditionSource()` / `operatorsFor()` (`conditionEvaluator.ts`) appliquent la même règle. Côté PHP : `FormsCore\DTO\DisplayCondition` (readonly) et l'enum `FormsCore\Enum\ConditionOperator`.

### 9.4 `ConditionEvaluatorService` (forms-core)

Service transverse et sans état, unique source de vérité de l'évaluation côté serveur.

| Méthode | Rôle |
| :--- | :--- |
| `validate(Question $question, array $orderedQuestions): list<string>` | erreurs de conception : source inexistante, `source.position >= question.position`, source `FILE`, opérateur incompatible, valeur invalide (option absente de la source, type de valeur incorrect) |
| `isSatisfied(DisplayCondition $condition, ?array $sourcePayload): bool` | évaluation d'une condition ; `false` si la source n'a pas de réponse |
| `visibleQuestionIds(array $orderedQuestions, array $payloadsByQuestionId): list<string>` | parcours dans l'ordre des positions ; une question dont la source est masquée est masquée (cascade) |

Utilisateurs :

| Appelant | Usage |
| :--- | :--- |
| `SurveyService::saveStructure` / `validate` | contrôle de conception (§9.5) ; à la validation, chaque erreur est remontée comme les autres (`scope: question`) |
| `ParticipantService::submit` | 1. normaliser les réponses reçues via les handlers ; 2. calculer les questions visibles ; 3. ignorer les valeurs des questions masquées ; 4. contrôler `required` et les formats **uniquement** sur les questions visibles ; 5. enregistrer de façon atomique (fonc §6.2) |
| `StatsService::compute` | nombre de répondants éligibles par question conditionnelle (`eligibleCount`) ; `answerRate = answersCount / eligibleCount` |
| `ExportService` | cellule vide pour une question masquée |

Évaluation des opérateurs : `CHOICE` sur l'ensemble `optionIds` de la réponse (une seule option pour un choix unique) ; `BOOLEAN` sur la valeur booléenne normalisée ; `NUMBER` / `RANGE` en comparaison de nombres (égalité à 1e-9 près). Toute autre source est refusée à la conception (`structureErrors` : « Une condition ne peut porter que sur une question « Oui / Non », à choix unique, « Chiffres » ou « Échelle ». ») et n'est jamais satisfaite.

### 9.5 Règles d'enregistrement de la structure (`forms_api`)

L'enregistrement, la réorganisation et la suppression des questions d'un brouillon passent tous par `PUT /api/admin/surveys/{id}/structure` (structure complète, l'ordre du tableau fait foi ; voir `docs/api.md`). Les règles de `displayCondition` y sont appliquées :

| Situation | Réponse |
| :--- | :--- |
| `sourceQuestionId` absent du payload (question source supprimée) | la condition est **retirée automatiquement** de la question dépendante ; enregistrement accepté |
| source présente mais placée **après ou au même rang** que la question (réordonnancement interdit) | **422** `{ message, errors: [{ scope: 'question', id, position, message }] }` ; rien n'est enregistré |
| source de type `FILE`, opérateur incompatible ou valeur de forme incorrecte | **422**, mêmes détails |
| valeur qui ne correspond plus à la source (ex. option supprimée) | accepté en `DRAFT` (brouillon incomplet), signalé à la validation et bloquant (fonc §3.3) |
| questionnaire hors `DRAFT` | **409** (immutabilité, fonc §2) |

Soumission participant : `POST /api/public/surveys/{token}/submit` applique le §9.4. Une valeur envoyée pour une question masquée n'est ni validée, ni enregistrée, ni comptée en violation.

### 9.6 Comportement de `forms_bo`

**Builder**
* Type `BOOLEAN` (libellé « Oui / Non ») : sélecteur de preset (`Oui / Non`, `Vrai / Faux`, `Personnalisé`) en `SegmentedControl` de `core-react`, deux champs de libellé toujours éditables, rendu (`Deux boutons` / `Interrupteur`), valeur par défaut (`Aucune` — boutons seulement —, vrai, faux), aperçu manipulable (`ui/BooleanControl`, partagé avec le formulaire participant).
* Implémentation des conditions : `pages/surveys/builder/conditions.ts` (libellés d'opérateurs, résumé, contrôles, ordre), `DisplayConditionEditor.tsx` ; une condition incomplète reste dans le builder mais n'est pas envoyée (`displayCondition: null`) ; l'enregistrement automatique est suspendu pendant un glisser-déposer (`useBuilder.hold`) ; changer le type d'une question source retire les conditions qui en dépendent.
* Éditeur « Afficher cette question uniquement si… » (`DisplayConditionEditor`) : question source parmi les **questions précédentes Oui / Non ou à choix unique** (radio, liste), opérateurs « est », « n'est pas », « est l'une de » (choix) ou « est », « n'est pas » (Oui / Non), valeur choisie dans les options du `CHOICE` ou les libellés du `BOOLEAN`. Indisponible sur la question en position 1 ; sans source possible, un message explique la règle. Une source qui change de type ou passe en choix multiple voit ses conditions dépendantes retirées.
* Badge « Conditionnelle — si Q{n} … » sur les cartes de question (`QuestionCard`), numéro de la source recalculé en direct.
* Glisser-déposer : avant d'appliquer un déplacement, `builderModel` vérifie `source.position < question.position` pour toutes les conditions ; en cas de violation, `ConfirmDialog` propose « Retirer la condition et déplacer » ou « Annuler ». Supprimer une question source retire les conditions dépendantes (avec mention dans la boîte de confirmation).

**Mode d'affichage (`displayMode`, fonc §6.4)**
* Entité `Survey` : colonne `display_mode` VARCHAR(16), enum `FormsCore\Enum\SurveyDisplayMode` (`LIST` par défaut, `ONE_BY_ONE`), groupes `survey.read` et `survey.public`. Migration `Version20261116031305`.
* `PATCH /api/admin/surveys/{id}` accepte `displayMode` (avec `title` / `description`) : 400 si valeur inconnue, 409 une fois la campagne lancée. Copié par `POST …/duplicate`.
* `showDuration` (option du questionnaire, booléen, défaut `true`, colonne `show_duration`) : afficher ou non la **durée estimée** ; le nombre de questions est toujours affiché. Même endpoint, mêmes règles ; 400 si la valeur n'est pas un booléen. Estimation calculée par `forms_bo` (`pages/participant/estimate.ts`).
* `forms_bo` : `participantModel.buildFlow(survey, mode)` produit les écrans et le stepper (vide sous 2 sections) ; `SurveyRunner` est le parcours unique, partagé par `/s/:token` et l'aperçu du builder (qui force le mode et désactive les contrôles par défaut).

**Brouillon participant (fonc §6.5)**
* `SurveyRecipient` : colonnes `draft_answers` (JSON, nullable), `draft_question_id` (VARCHAR 36), `draft_saved_at` ; `saveDraft()`, `clearDraft()`, `getDraft()`. Effacé par `markAnswered()`, par `DELETE …/draft` et à la clôture (`SurveyRecipientRepository::clearDrafts()` appelé par `LifecycleService::close`).
* `GET /api/public/surveys/{token}` renvoie `draft: { answers, questionId, savedAt } | null`.
* `PUT /api/public/surveys/{token}/draft` : corps `{ answers, questionId? }` ; seules les questions du questionnaire sont gardées, chaînes (10 000 car.) ou listes de chaînes (100 éléments) ; pas de validation métier ni de limitation de débit ; réponse `{ savedAt }` ; 403 si le lien ne permet plus de répondre.
* `DELETE /api/public/surveys/{token}/draft` : brouillon effacé et fichiers en attente supprimés (`AssetService::deleteAllPending`) ; 204.
* `forms_bo` : écran de reprise dans `ParticipantPage` ; `SurveyRunner` (`initialQuestionId`, `onProgress`) ; enregistrement 800 ms après la dernière modification.

**Formulaire participant (`/s/:token`)**
* Barre de navigation, puis bandeau collé en haut (`position: sticky`) : stepper puis barre de progression.
* `conditionEvaluator.ts` (miroir de `ConditionEvaluatorService`, testé sur les mêmes cas) est rejoué à chaque changement de réponse ; les questions masquées ne sont pas rendues.
* Une question qui redevient masquée voit sa valeur locale réinitialisée ; ses fichiers déposés sont retirés (`DELETE /api/public/surveys/{token}/files/{assetId}`).
* Validation de l'étape et progression calculées sur les seules questions visibles ; étapes entièrement masquées sautées (fonc §6.4) ; payload de soumission limité aux questions visibles.
* Rendu `BOOLEAN` : `SegmentedControl` (rôle `radiogroup`) à deux segments, pleine largeur sur mobile.

**Statistiques**
* Widget `BOOLEAN` : effectifs et pourcentages des deux libellés, barre comparative.
* Questions conditionnelles : « Affichée à {eligibleCount} répondants » et rappel de la condition ; taux de réponse sur `eligibleCount`.

---

## 10. Sections à compléter

<!-- TODO : reprendre depuis la version complète de la spec technique (les contrats QuestionConfig / AnswerPayload / displayCondition sont au §9) : schéma Doctrine complet, endpoints REST /api/admin/* et /api/public/*, authentification JWT, Voters, Scheduler (crons de la spec fonctionnelle §9), envoi des e-mails, détail des responsabilités de core-php / core-ts / core-react. -->
