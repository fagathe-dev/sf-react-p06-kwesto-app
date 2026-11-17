# Jeu de données de démonstration

Les fixtures de `apps/forms_api/src/DataFixtures` (`doctrine/doctrine-fixtures-bundle` + `fakerphp/faker`, locale `fr_FR`, graine fixe) couvrent tous les états du cycle de vie, tous les statuts de destinataire, les 7 types de questions, des fichiers déposés et la télémétrie.

```bash
cd apps/forms_api
php bin/console doctrine:fixtures:load -n          # purge puis recharge la base
```

## Comptes

Mot de passe commun : `Kwesto!2026` (constante `AppFixtures::PASSWORD`, développement uniquement).

| E-mail | Rôle | Contenu |
| :--- | :--- | :--- |
| `admin@kwesto.test` | `ROLE_ADMIN` | 1 campagne active + espace Administration |
| `camille@kwesto.test` | `ROLE_USER` | un questionnaire dans chaque état (scénarios ci-dessous) |
| `alex@kwesto.test` | `ROLE_USER` | dossier de candidature ouvert, baromètre clôturé manuellement, brouillon |
| `sacha@kwesto.test` | `ROLE_USER` | un brouillon, un questionnaire validé |

## Scénarios (compte de Camille)

| Questionnaire | État | Ce qu'il permet de tester |
| :--- | :--- | :--- |
| Baromètre (incomplet) | `DRAFT` | builder, sections, validation qui échoue |
| Inscription au séminaire | `DRAFT` modifié il y a 75 jours | nettoyage hebdomadaire des brouillons inactifs |
| Évaluation de formation | `VALIDATED`, 24 destinataires | liste de diffusion, lancement |
| Baromètre de satisfaction | `ACTIVE`, 60 destinataires, 3 sections | stepper, statistiques temps réel |
| *(conditions)* | Dossier : « Nom de naissance » si Civilité = Madame · Séminaire : ateliers / repas si présent ≠ Non, date et « accompagné(e) ? » (interrupteur, Non par défaut) si Oui, nombre d'accompagnants si accompagné · Télétravail : « Que vous manque-t-il ? » si équipement En partie ou Non | réponses générées en respectant les conditions |
| *(mode d'affichage)* | « Dossier de candidature » et « Événement » en `ONE_BY_ONE`, les autres en `LIST` | lien `bbbb…bbbb` : question par question |
| Enquête télétravail | `ACTIVE`, ~12 % de réponses, échéance dans 4,5 jours | relance J-5/J-4 (`low-response-reminders`) |
| Inscription au séminaire | `ACTIVE`, échéance dépassée | clôture `DEADLINE_REACHED` (`close-expired`) |
| Évaluation de formation | `CLOSED` il y a 40 jours, 100 % | statistiques figées, anonymisation RGPD (`anonymize-telemetry`) |
| Dossier de candidature | `CLOSED` il y a 2 jours | questions FILE, galerie, archive .zip |

Les destinataires mélangent les statuts `SENT`, `OPENED`, `ANSWERED` (avec télémétrie : 7 navigateurs / appareils réalistes) et `BOUNCED`. Les fichiers sont de vrais PDF, PNG, JPEG et XLSX (le type MIME réel est vérifié), stockés dans `UPLOAD_STORAGE_DIR`.

## Liens participants fixes

À ouvrir dans le back-office : `{APP_BO_URL}/s/<jeton>` (par défaut `http://localhost:5173/s/<jeton>`).

| Jeton | Écran attendu |
| :--- | :--- |
| `aaaa…` (64 × `a`) | Baromètre en étapes, réponse possible |
| `bbbb…` (64 × `b`) | Dossier de candidature avec dépôt de fichiers |
| `cccc…` (64 × `c`) | Télétravail, formulaire sur une page |
| `dddd…` (64 × `d`) | « Vous avez déjà répondu… » |
| `eeee…` (64 × `e`) | « Ce questionnaire est désormais clôturé… » |

Une soumission consomme le lien : rechargez les fixtures pour le réutiliser. Les constantes sont dans `SurveyFixtures` (`TOKEN_STEPPER`, `TOKEN_FILES`…).

## Créer un administrateur

La commande vient de `core-php` ; `App\Security\AdminUserManager` la relie au modèle `User` de kwesto (le « nom d'utilisateur » devient le nom affiché, la connexion se fait par e-mail) :

```bash
php bin/console core-php:create-admin-user                                   # saisie interactive, mot de passe masqué
php bin/console core-php:create-admin-user "Dominique Admin" admin@exemple.fr 'MotDePasse!2026'
```

Mot de passe : 8 caractères minimum. La création est journalisée dans `logs/command/` (sans le mot de passe).

## Créer un compte créateur

```bash
php bin/console app:user:create camille@exemple.fr "Camille Martin"          # mot de passe demandé
php bin/console app:user:create sacha@exemple.fr "Sacha Lefèvre" 'MotDePasse!2026' --admin
```

## Remarques

- En `dev`, le contrôle DNS des destinataires est désactivé (`core_php.email.check_deliverability: false`) : les adresses des fixtures (`example.com`…) ne recevraient sinon aucune relance.
- Avec `MAILER_DSN=null://null`, aucun e-mail ne part ; chaque envoi « réussi » est signalé dans `logs/mailer/`.
- Exécuter les tâches planifiées après un chargement fait évoluer les scénarios (clôture, relances) : rechargez les fixtures pour revenir à l'état initial.
