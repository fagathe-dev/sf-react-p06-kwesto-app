<?php

declare(strict_types=1);

namespace App\Scheduler\Message;

/**
 * Tâche planifiée (specs_fonc §9). Une valeur = une ligne du tableau des crons.
 */
enum CronTask: string
{
    case CloseExpired = 'close-expired';
    case LowResponseReminders = 'low-response-reminders';
    case SmtpRetry = 'smtp-retry';
    case PurgeOrphanAssets = 'purge-orphan-assets';
    case AnonymizeTelemetry = 'anonymize-telemetry';
    case BuildArchives = 'build-archives';
    case RefreshStats = 'refresh-stats';
    case WeeklyCleanup = 'weekly-cleanup';

    public function label(): string
    {
        return match ($this) {
            self::CloseExpired => 'Clôture des sondages expirés',
            self::LowResponseReminders => 'Relance faible participation J-4/J-5',
            self::SmtpRetry => 'Rattrapage SMTP',
            self::PurgeOrphanAssets => 'Purge des fichiers temporaires orphelins',
            self::AnonymizeTelemetry => 'Anonymisation RGPD de la télémétrie',
            self::BuildArchives => 'Pré-génération des archives ZIP',
            self::RefreshStats => 'Recalcul des instantanés statistiques',
            self::WeeklyCleanup => 'Nettoyage technique hebdomadaire',
        };
    }

    /**
     * Expression cron (fuseau Europe/Paris).
     */
    public function cron(): string
    {
        return match ($this) {
            self::CloseExpired => '*/5 * * * *',
            self::LowResponseReminders => '0 8-20 * * *',
            self::SmtpRetry => '*/15 * * * *',
            self::PurgeOrphanAssets => '0 2 * * *',
            self::AnonymizeTelemetry => '30 2 * * *',
            self::BuildArchives => '0 3 * * *',
            self::RefreshStats => '30 3 * * *',
            self::WeeklyCleanup => '0 4 * * 0',
        };
    }
}
