# E-mails transactionnels

Socle : `AbstractMailerHandler` de core-php (validation du DTO, contrôle DNS du destinataire, expéditeur, logs, exceptions) et le layout `@CorePhp/layout.html.twig` avec ses composants. kwesto ne déclare que ses DTO, handlers et templates (`packages/forms-core`).

## Catalogue

| Code | Destinataire | Déclencheur | Handler / template |
| :--- | :--- | :--- | :--- |
| `SURVEY_INVITATION` | participant | lancement (`SendInvitationMessage`, worker `async`) | `SurveyInvitationMailHandler` · `survey_invitation` |
| `SURVEY_REMINDER` | participant `SENT` / `OPENED` | tâche `low-response-reminders` : J-5 à J-4, ≤ 20 %, lancé depuis ≥ 48 h, une seule fois | `SurveyReminderMailHandler` · `survey_reminder` |
| `SURVEY_RECEIPT` | participant | soumission | `SurveyReceiptMailHandler` · `survey_receipt` |
| `CREATOR_LOW_RESPONSE` | créateur | avec les relances | `CreatorLowResponseMailHandler` · `creator_low_response` |
| `CREATOR_SURVEY_CLOSED` | créateur | clôture (100 %, échéance, manuelle) | `CreatorSurveyClosedMailHandler` · `creator_survey_closed` |
| `CREATOR_BOUNCE_ALERT` | créateur | rejet définitif d'une invitation | `CreatorBounceAlertMailHandler` · `creator_bounce_alert` |
| `CONTACT_REQUEST` | `CONTACT_RECIPIENT_EMAIL` | formulaire du site vitrine (Reply-To : l'expéditeur) | `ContactRequestMailHandler` · `contact_request` |

Templates : `packages/forms-core/templates/emails/*.html.twig` (namespace `@FormsCore`). Toute donnée saisie est échappée (`|e`) avant d'être passée aux composants, qui affichent `content` en brut.

Expéditeur : `MAILER_FROM_ADDRESS` au nom de `MAILER_FROM_NAME` (ponts vers `core_php.email.default_sender` et `core_php.email.app_name` dans les `services.yaml` des applications). Variables facultatives de core-php : `SUPPORT_EMAIL` (bloc d'aide en pied d'e-mail), `LOGO_PATH` (logo, relatif à `DEFAULT_URI`).

## Envoi et rejets

- Les invitations partent du worker Messenger ; le mailer envoie en direct (`message_bus: false`) pour qu'`InvitationSender` connaisse le résultat de chaque envoi.
- Rejet définitif (domaine sans serveur de messagerie, refus SMTP 55x, 5 échecs temporaires) → destinataire `BOUNCED`, exclu du calcul des 100 %, `CREATOR_BOUNCE_ALERT`, et clôture `ALL_ANSWERED` si tous les autres ont répondu.
- Échec temporaire → reste `PENDING`, repris par la tâche `smtp-retry` toutes les 15 minutes.
- Les rejets signalés plus tard par le serveur destinataire (DSN dans la boîte d'expédition) demandent un webhook du fournisseur d'e-mails : non implémenté.
- Les e-mails secondaires (accusé de réception, alertes) sont « non bloquants » (`MailDispatcher::dispatchSilently`) : un échec est journalisé sans annuler l'opération.

## Aperçu dans l'espace Administration

`/admin/emails` liste les templates métier et la vitrine des composants prédéfinis de core-php (`core-php-components`). Chaque aperçu : rendu exact (même `BodyRenderer` que l'envoi), affichage ordinateur ou mobile dans une iframe sans script, jeu de données réaliste modifiable, envoi d'un test à l'adresse de l'administrateur connecté.

## Ajouter un e-mail

1. `DTO\Mail\XxxMailDto` (readonly, contraintes `Assert`).
2. `Mailer\XxxMailHandler extends AbstractMailerHandler implements MailHandlerInterface` (template, objet, destinataires, contexte) — découvert automatiquement par `MailDispatcher`.
3. `templates/emails/xxx.html.twig` qui étend `@CorePhp/layout.html.twig`.
4. `Mailer\Preview\XxxMailPreview extends AbstractMailPreview` (slug, code, champs du jeu de données) — découvert par `EmailPreviewService`.
5. Envoi : `$mailDispatcher->dispatch(new XxxMailDto(…))` (ou `dispatchSilently`).

Journaux : `logs/mailer/<handler>-jj-mm-aaaa.json` (sans le contenu du DTO). Avec `MAILER_DSN=null://null`, un avertissement `mailer.null_transport` signale que rien n'est réellement parti.
