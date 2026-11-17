<?php

declare(strict_types=1);

namespace App\Command;

use App\Scheduler\CronRunner;
use App\Scheduler\Message\CronTask;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exécute immédiatement une tâche planifiée (test, rattrapage manuel, crontab système).
 */
#[AsCommand(name: 'app:cron:run', description: 'Exécute une tâche planifiée de kwesto (specs_fonc §9).')]
final class RunCronCommand
{
    public function __construct(private readonly CronRunner $runner)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Tâche : close-expired, low-response-reminders, smtp-retry, purge-orphan-assets, anonymize-telemetry, build-archives, refresh-stats, weekly-cleanup, ou "list"')]
        string $task = 'list',
    ): int {
        if ('list' === $task) {
            $io->table(['Tâche', 'Planification', 'Description'], array_map(
                static fn (CronTask $t): array => [$t->value, $t->cron(), $t->label()],
                CronTask::cases(),
            ));

            return Command::SUCCESS;
        }

        $cronTask = CronTask::tryFrom($task);
        if (null === $cronTask) {
            $io->error(\sprintf('Tâche inconnue : "%s". Lancez "app:cron:run list".', $task));

            return Command::INVALID;
        }

        $report = $this->runner->run($cronTask);
        if (null === $report) {
            $io->warning('La tâche est déjà en cours d\'exécution.');

            return Command::SUCCESS;
        }

        $io->success($cronTask->label());
        $io->definitionList(...array_map(
            static fn (string $key, mixed $value): array => [$key => \is_scalar($value) ? (string) $value : json_encode($value)],
            array_keys($report),
            $report,
        ));

        return Command::SUCCESS;
    }
}
