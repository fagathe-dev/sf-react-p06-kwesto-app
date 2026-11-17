<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Scheduler\Message\RunCronTask;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunCronTaskHandler
{
    public function __construct(private readonly CronRunner $runner)
    {
    }

    public function __invoke(RunCronTask $message): void
    {
        $this->runner->run($message->task);
    }
}
