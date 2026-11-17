# Documentation kwesto

Documentation technique du monorepo. Les règles métier font foi dans [`specs/specs_fonc.prompt.md`](../specs/specs_fonc.prompt.md), l'architecture cible dans [`specs/specs_tech.prompt.md`](../specs/specs_tech.prompt.md) ; ces pages décrivent **ce qui est implémenté** et **comment s'en servir**.

| Page | Contenu |
| :--- | :--- |
| [Architecture](architecture.md) | Topologie du monorepo, rôle de chaque application, flux d'une campagne |
| [Installation](installation.md) | Prérequis, variables d'environnement, base de données, clés JWT, démarrage |
| [Jeu de données (fixtures)](fixtures.md) | Comptes de démonstration, scénarios, liens participants fixes, création d'un administrateur |
| [Package forms-core](forms-core.md) | Entités, enums, contrats JSON, services, points d'extension |
| [API REST](api.md) | Authentification, endpoints `/api/admin/*` et `/api/public/*`, erreurs |
| [Back-office forms_bo](forms-bo.md) | Pages, builder, formulaire participant, conventions React |
| [Site vitrine forms_front](forms-front.md) | Pages, SEO, chaîne esbuild + sass, exclusions |
| [E-mails](emails.md) | Catalogue, templates, aperçu admin, ajouter un e-mail |
| [Tâches planifiées](scheduler.md) | Crons, worker, exécution manuelle, déploiement |
| [Tests](tests.md) | Lancer les suites de chaque application, pièges connus |

## Conventions de cette documentation

- Une page par sujet, 250 lignes au maximum.
- Les chemins sont relatifs à la racine du monorepo.
- Aucun secret : les valeurs sensibles vivent dans les `.env.local` (jamais lus ni recopiés).
