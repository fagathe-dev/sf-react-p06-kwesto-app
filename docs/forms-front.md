# Site vitrine (`apps/forms_front`)

Symfony 8 + Twig, rendu serveur. Interactions en TypeScript + `core-ts`, styles en SCSS, compilés par la chaîne npm de l'application.

## Exclusions obligatoires

Aucun Symfony UX, Stimulus ni AssetMapper (specs_tech §5.1.1) :

- paquets `symfony/ux-turbo`, `symfony/stimulus-bundle`, `symfony/asset-mapper` retirés (ainsi que `symfony/notifier`, inutile) ; pas d'`importmap.php`, de `assets/controllers/` ni de `importmap()` ;
- la protection CSRF est **adossée à la session** : la recette Flex activait des jetons « stateless » reposant sur un contrôleur Stimulus, et Symfony ajoute par défaut `data-controller="csrf-protection"` au champ caché (`framework.form.csrf_protection.field_attr: []` dans `config/packages/csrf.yaml`) ;
- aucun attribut `data-controller`, `data-action` ou `data-*-target` dans les templates (vérifié par les tests).

## Pages

| Route | Gabarit | Contenu |
| :--- | :--- | :--- |
| `/` | `pages/home.html.twig` | proposition de valeur, 3 étapes, aperçu du formulaire, onglets des 7 types |
| `/fonctionnalites` | `pages/features.html.twig` | types de questions, lien unique, relances, statistiques |
| `/cas-usage` | `pages/use_cases.html.twig` | enquêtes internes, collecte de dossiers, évaluations post-formation |
| `/tarifs` | `pages/pricing.html.twig` | offres + FAQ (accordéons, JSON-LD `FAQPage`) |
| `/contact` | `pages/contact.html.twig` | formulaire (CSRF, pot de miel, 5 envois / 15 min / IP) |
| `/mentions-legales` | `pages/legal.html.twig` | **emplacements à compléter** par l'éditeur (raison sociale, SIREN, hébergeur…) |
| `/confidentialite` | `pages/privacy.html.twig` | RGPD, durées de conservation, cookies |
| `/sitemap.xml`, `/robots.txt` | `seo/*.twig` | générés dynamiquement |

Les tarifs et témoignages affichés sont des contenus d'exemple à remplacer.

## SEO

- `App\Seo\PageCatalog` : une entrée par page (gabarit, titre, meta description, priorité, fréquence). Contrôleur, gabarit maître et sitemap lisent cette même source : **ajouter une page = une entrée + un gabarit + une route**.
- `templates/base.html.twig` : `<title>`, meta description, `robots: index, follow`, `rel="canonical"`, OpenGraph, Twitter Cards, JSON-LD `SoftwareApplication` ; bloc `structured_data` pour les données propres à une page.
- Canonical, sitemap et robots.txt sont construits sur `DEFAULT_URI`, jamais sur l'hôte de la requête.
- `framework.disallow_search_engine_index: false` : sans cela, Symfony ajoute `X-Robots-Tag: noindex` en mode debug.
- Les CTA pointent vers `APP_BO_URL` (fonction Twig `app_url('/login')`).

## Chaîne de compilation

Reprise du dépôt de référence `sf-p05-design-system` (commit `e527042`) : `scripts/compile-ts.js`, `scripts/compile-scss.js`, `.npmrc`, `.prettierrc` et `tsconfig.json` sont identiques ; `package.json` sans Stimulus ni Turbo.

```bash
npm run build      # production : minifié, sans source maps, code retour 1 en cas d'échec
npm run compile    # développement : une passe, avec source maps
npm run dev        # recompilation continue (TS et SCSS)
```

| Source | Sortie |
| :--- | :--- |
| `assets/ts/main.ts` (toutes les pages) | `public/js/main.js` |
| `assets/ts/pages/{home,pricing,contact}.ts` | `public/js/pages/*.js` ; code partagé (core-ts) dans `public/js/chunks/` |
| `assets/scss/ds-site.scss` | `public/css/ds-site.css` |

Twig référence les fichiers compilés avec `asset()` (`symfony/asset` seul) ; `App\Asset\FileMtimeVersionStrategy` ajoute `?v=<date de modification>` pour invalider le cache navigateur. `public/css` et `public/js` sont ignorés par git : compiler au déploiement.

Points à trancher (spec) : `compile-scss.js` garde son étape `compileIcons` (sans effet ici, aucun `public/assets/ds-icons.css`). Le `tsconfig.json` repris déclenche des avertissements de dépréciation TypeScript 6 (`moduleResolution: node`, `baseUrl`) : sans incidence sur esbuild, à adapter le jour où le dépôt de référence évolue (`tsc --noEmit --ignoreDeprecations 6.0` pour vérifier les types).

## Interactions (`assets/ts`)

| Module | Rôle |
| :--- | :--- |
| `components/nav.ts` | menu mobile (`aria-expanded`, Échap, fermeture au passage en grand écran) |
| `components/consent.ts` | bandeau de consentement, choix mémorisé (`StorageService` de core-ts), événement `kwesto:consent` |
| `components/copy.ts` | boutons `[data-copy]` (`copyWithButtonFeedback` de core-ts) |
| `components/accordion.ts` | FAQ : une réponse ouverte, navigation au clavier |
| `components/tabs.ts` | onglets de démonstration (motif ARIA « tabs ») |
| `pages/contact.ts` | validation à la saisie (`isEmail`, `isEmpty` de core-ts) |

Amélioration progressive : sans JavaScript, la FAQ et les onglets restent entièrement lisibles.

## SCSS (`assets/scss`)

`abstracts/` (primitives, mixins) · `foundation/` (police, variables CSS, reset, typographie) · `layout/` · `components/` · `pages/`. Les couleurs passent par des variables CSS (`--color-*`) ; l'encart d'aide reprend le style imposé du formulaire participant (bleu clair, texte bleu foncé).
