<?php

declare(strict_types=1);

namespace App\Scheduler\Message;

final readonly class RunCronTask
{
    public function __construct(public CronTask $task)
    {
    }
}
