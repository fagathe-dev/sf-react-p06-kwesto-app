# Package `packages/forms-core`

Bundle Symfony partagé (`fagathe-dev/forms-core`, namespace `FormsCore\`), installé dans `forms_api` et `forms_front`. `FormsCoreBundle` déclare le mapping Doctrine de `src/Entity`, la fonction DQL `MATCH_AGAINST`, les paramètres `forms_core.*`, le namespace Twig `@FormsCore` et l'autowiring de tous les services : les applications n'ont rien à configurer.

## Arborescence

| Dossier | Contenu |
| :--- | :--- |
| `Entity/` | `User`, `Survey`, `SurveySection`, `Question`, `SurveyRecipient`, `SurveyAnswer`, `UploadedAsset` (UUID v7) |
| `Enum/` | `SurveyStatus`, `QuestionType`, `RecipientStatus`, `CloseReason`, `ChoiceWidget`, `DeviceType`, `LogChannel`, `UserRole` |
| `DTO/QuestionConfig/` | Contrat de configuration par type : `TextConfig`, `NumberConfig`, `ChoiceConfig`, `DateConfig`, `FileConfig` |
| `DTO/AnswerPayload/` | Contrat de réponse par type : `TextAnswer`, `NumberAnswer`, `ChoiceAnswer`, `DateAnswer`, `FileAnswer` |
| `DTO/Mail/` | Un DTO par e-mail (voir [emails.md](emails.md)) |
| `QuestionType/` | Un handler par type + `QuestionTypeRegistry` |
| `Repository/` | Requêtes et écritures (`PersistenceTrait` : `save`, `remove`, `flush`, `transactional`, `paginateQuery`) |
| `Service/` | Logique métier (voir ci-dessous) |
| `Mailer/` | `MailDispatcher`, handlers, aperçus (`Preview/`) |
| `Security/Voter/` | `SurveyVoter` : un questionnaire n'est accessible qu'à son créateur |
| `Trait/` | `FullTextSearchTrait`, `ChannelLoggerTrait` |
| `templates/emails/` | Templates Twig des e-mails (`@FormsCore/emails/*`) |

## Contrats JSON polymorphes

Configuration d'une question (`Question::$config`) :

| Type | Configuration |
| :--- | :--- |
| `TEXT` | `{ multiline, minLength?, maxLength?, placeholder? }` (`maxLength` ≤ 5000) |
| `NUMBER` | `{ allowDecimal, min?, max?, step?, unit? }` |
| `CHOICE` | `{ widget: radio\|checkbox\|select, multiple, options: [{ id, label }] }` (≥ 2 options ; `multiple` dérivé du widget) |
| `DATE` | `{ minDate?, maxDate? }` au format `YYYY-MM-DD` |
| `FILE` | `{ allowedMimeTypes: [], maxSizeMb?, maxFiles }` (`maxSizeMb` plafonné à `UPLOAD_MAX_SIZE_MB` à l'enregistrement) |

Réponse enregistrée (`SurveyAnswer::$payload`) :

| Type | Payload | Saisie acceptée |
| :--- | :--- | :--- |
| `TEXT` | `{ value: "…" }` | chaîne, trim, espaces normalisés si réponse courte |
| `NUMBER` | `{ value: 12.5 }` | `"12,5"`, `"12.5"`, `12.5`, espaces de milliers |
| `CHOICE` | `{ optionIds: ["a"] }` | identifiant ou liste, dédoublonnée, ordre des options |
| `DATE` | `{ value: "2026-11-13" }` | date calendaire valide |
| `FILE` | `{ assetIds: ["<uuid>"] }` | fichiers déposés par ce destinataire, pour cette question |

Les réponses `TEXT` sont recopiées dans `SurveyAnswer::$searchText`, seule colonne indexable en FULLTEXT.

## Ajouter un type de question

Open/Closed : aucun `switch` sur le type dans le code métier.

1. Ajouter le case dans `Enum\QuestionType`.
2. Créer `DTO\QuestionConfig\XxxConfig` et `DTO\AnswerPayload\XxxAnswer`.
3. Créer `QuestionType\XxxHandler implements QuestionTypeHandlerInterface` (normalisation et validation de la configuration, validation de la réponse, texte recherchable, statistiques, valeur d'export). Il est découvert automatiquement par `QuestionTypeRegistry`.
4. Côté `forms_bo` : éditeur de configuration, champ participant, bloc de statistiques.

## Services

| Service | Responsabilité |
| :--- | :--- |
| `SurveyService` | Liste, création, métadonnées, structure complète (sections + questions, numérotation continue), validation, duplication, suppression |
| `DistributionService` | Liste de diffusion (saisie libre ou CSV, `EmailListParser`), lancement (jetons, `ACTIVE`, messages), clôture manuelle |
| `InvitationSender` | Envoi de `SURVEY_INVITATION`, `SENT` ou `BOUNCED` (rejet définitif ou 5 échecs), alerte au créateur |
| `ParticipantService` | Accès `/s/{token}` (ordre de specs_fonc §6.1), soumission atomique sous verrou `SELECT … FOR UPDATE` |
| `AssetService` / `AssetStorage` | Dépôt (MIME réel + poids via core-php), retrait, téléchargement ; stockage privé hors `public/` |
| `TelemetryService` | IP (proxy de confiance), User-Agent, navigateur, version majeure, système, appareil ; anonymisation /24 – /48 |
| `LifecycleService` | Clôtures (`ALL_ANSWERED`, `DEADLINE_REACHED`, `MANUAL`), relance J-5/J-4 |
| `StatsService` | Indicateurs globaux et par question, verbatims, galerie, instantanés |
| `ExportService` / `ArchiveService` | CSV compatible Excel (BOM, `;`, formules neutralisées) ; ZIP en streaming ou pré-généré |
| `SearchService` | Recherche plein texte du créateur (questionnaires, questions, destinataires, verbatims) |
| `EmailPreviewService` / `LogViewerService` | Espace Administration |
| `MaintenanceService` | Purges, anonymisation, archives, instantanés (tâches nocturnes) |
| `ContactService` | Formulaire de contact du site vitrine |
| `PlatformConfig` | `uploadMaxSizeMb` et formats de fichiers proposés |

Les services renvoient l'objet de `ResponseTrait` (core-php) : `{ data, status, headers, context }` ; le contrôleur de l'application le transforme en réponse JSON.

## Recherche plein texte

Index FULLTEXT déclarés sur les entités (`flags: ['fulltext']`) et créés par migration : `survey(title, description)`, `question(label, help_text)`, `survey_recipient(email)`, `survey_answer(search_text)`.

```php
->andWhere('MATCH_AGAINST(s.title, s.description) AGAINST (:term BOOLEAN) > 0')
->setParameter('term', $this->toBooleanQuery($query))   // "+mot1* +mot2*"
```

La saisie est normalisée (`FullTextSearchTrait`) : longueur limitée, opérateurs booléens retirés. Les mots de moins de 3 lettres (`innodb_ft_min_token_size`) basculent sur un repli `LIKE` échappé.

## Pièges connus

- **Paramètres UUID** : Doctrine ne convertit pas en binaire une entité ou un tableau d'entités passés à `setParameter()` dans une requête DQL. Toujours typer : `->setParameter('survey', $survey->getId(), UuidType::NAME)` ou, pour une liste, des identifiants binaires avec `ArrayParameterType::BINARY`.
- **Journaux** : `ChannelLoggerTrait` écrit dans `logs/<canal>/` sans résoudre l'IP publique (le `Logger` de core-php interrogerait sinon un service externe à chaque requête).
- Les entités sont mappées une seule fois par `FormsCoreBundle` : ne pas ajouter de mapping `FormsCore` dans les `doctrine.yaml` des applications.
