<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Scheduler\Message\CronTask;
use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Logger\JsonLoggerRotation;
use FormsCore\Enum\LogChannel;
use FormsCore\Service\InvitationSender;
use FormsCore\Service\LifecycleService;
use FormsCore\Service\MaintenanceService;
use FormsCore\Trait\ChannelLoggerTrait;
use Symfony\Component\Lock\LockFactory;

/**
 * Exécute une tâche planifiée sous verrou (jamais deux exécutions simultanées de la même tâche)
 * et journalise son rapport dans le canal « cron ».
 */
final class CronRunner
{
    use ChannelLoggerTrait;

    public function __construct(
        private readonly LifecycleService $lifecycle,
        private readonly InvitationSender $invitations,
        private readonly MaintenanceService $maintenance,
        private readonly JsonLoggerRotation $logRotation,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * @return array<string, mixed>|null rapport de la tâche, null si elle tourne déjà
     */
    public function run(CronTask $task): ?array
    {
        $lock = $this->lockFactory->createLock('kwesto-cron-'.$task->value, ttl: 3600);
        if (!$lock->acquire()) {
            return null;
        }

        $start = microtime(true);

        try {
            $report = match ($task) {
                CronTask::CloseExpired => ['closed' => $this->lifecycle->closeExpired()],
                CronTask::LowResponseReminders => $this->lifecycle->sendLowResponseReminders(),
                CronTask::SmtpRetry => $this->invitations->retryPending(),
                CronTask::PurgeOrphanAssets => ['purged' => $this->maintenance->purgeOrphanAssets()],
                CronTask::AnonymizeTelemetry => ['anonymized' => $this->maintenance->anonymizeTelemetry()],
                CronTask::BuildArchives => ['built' => $this->maintenance->buildArchives()],
                CronTask::RefreshStats => ['refreshed' => $this->maintenance->refreshStatsSnapshots()],
                CronTask::WeeklyCleanup => [
                    'drafts' => $this->maintenance->purgeStaleDrafts(),
                    // Rotation / archivage des anciens journaux : rétention de core-php (30 jours)
                    'logs' => $this->logRotation->deleteOldFiles()['files_deleted'] ?? 0,
                ],
            };

            $report['durationMs'] = (int) round((microtime(true) - $start) * 1000);
            $this->logTo(LogChannel::Cron, LoggerLevelEnum::Info, $task->label(), 'cron.'.$task->value, $report);

            return $report;
        } catch (\Throwable $e) {
            $this->logTo(LogChannel::Cron, LoggerLevelEnum::Error, $task->label().' : échec.', 'cron.'.$task->value.'.error', [], $e);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
