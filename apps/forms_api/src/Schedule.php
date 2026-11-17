<?php

declare(strict_types=1);

namespace App;

use App\Scheduler\Message\CronTask;
use App\Scheduler\Message\RunCronTask;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tâches automatisées (specs_fonc §9). Exécution : `bin/console messenger:consume scheduler_default`
 * (processus permanent, voir docs). Exécution ponctuelle : `bin/console app:cron:run <tâche>`.
 */
#[AsSchedule]
final class Schedule implements ScheduleProviderInterface
{
    private const TIMEZONE = 'Europe/Paris';

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getSchedule(): SymfonySchedule
    {
        $schedule = (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);

        foreach (CronTask::cases() as $task) {
            $schedule->add(RecurringMessage::cron($task->cron(), new RunCronTask($task), new \DateTimeZone(self::TIMEZONE)));
        }

        return $schedule;
    }
}
