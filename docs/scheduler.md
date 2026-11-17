# Tâches planifiées (`apps/forms_api`)

Les crons de specs_fonc §9 sont déclarés avec Symfony Scheduler (`src/Schedule.php`, fuseau Europe/Paris). Chaque tâche passe par `App\Scheduler\CronRunner` : verrou (`symfony/lock`) pour ne jamais exécuter deux fois la même tâche en parallèle, rapport chiffré journalisé dans `logs/cron/`.

| Tâche | Planification | Effet |
| :--- | :--- | :--- |
| `close-expired` | toutes les 5 min | clôt les `ACTIVE` échus (`DEADLINE_REACHED`) → `CREATOR_SURVEY_CLOSED` |
| `low-response-reminders` | toutes les heures, 08h–20h | fenêtre `[expiresAt − 5 j, expiresAt − 4 j]`, ≤ 20 %, lancé depuis ≥ 48 h, jamais relancé → `SURVEY_REMINDER` + `CREATOR_LOW_RESPONSE` |
| `smtp-retry` | toutes les 15 min | réessaie les invitations restées `PENDING`, marque les rejets définitifs `BOUNCED` |
| `purge-orphan-assets` | 02h00 | supprime fichiers et lignes des dépôts jamais rattachés depuis > 24 h |
| `anonymize-telemetry` | 02h30 | `CLOSED` depuis > 30 j : IP tronquée (/24 en IPv4, /48 en IPv6), statistiques agrégées conservées |
| `build-archives` | 03h00 | archive .zip des questionnaires avec questions FILE clôturés dans les dernières 24 h |
| `refresh-stats` | 03h30 | instantané des statistiques des campagnes actives volumineuses (≥ 1 000 réponses) |
| `weekly-cleanup` | dimanche 04h00 | supprime les `DRAFT` inactifs depuis > 60 j ; rotation des journaux de core-php (30 jours) |

## Exécution

Processus permanent, à superviser en production (systemd, Supervisor) :

```bash
php bin/console messenger:consume scheduler_default --time-limit=3600 -vv
```

Le planificateur est « stateful » (cache applicatif) : après un arrêt, seule la dernière exécution manquée de chaque tâche est rattrapée.

Exécution ponctuelle, pour tester ou depuis une crontab système :

```bash
php bin/console app:cron:run list               # tâches, planification, description
php bin/console app:cron:run close-expired
php bin/console app:cron:run low-response-reminders
```

## Worker des invitations

Les invitations sont des messages `FormsCore\Message\SendInvitationMessage` routés vers le transport `async` (`MESSENGER_TRANSPORT_DSN`, Doctrine par défaut) :

```bash
php bin/console messenger:consume async --time-limit=3600 -vv   # table messenger_messages créée par la migration
```

En test, `async` est synchrone : les invitations partent pendant la requête de lancement.

## Journaux de l'espace Administration

| Canal | Écrit par |
| :--- | :--- |
| `mailer` | handlers d'e-mails (core-php), `InvitationSender`, aperçus envoyés en test |
| `cron` | `CronRunner`, `ArchiveService` |
| `app` | création, validation, lancement, clôture, soumissions, erreurs 5xx |
| `security` | accès refusés (403) |

Fichiers : `apps/forms_api/logs/<canal>/<source>-jj-mm-aaaa.json`, consultables dans `/admin/logs` (filtres par canal, sévérité, période et texte).
