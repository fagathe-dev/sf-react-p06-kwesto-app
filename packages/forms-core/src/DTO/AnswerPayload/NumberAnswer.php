<?php

declare(strict_types=1);

namespace FormsCore\DTO\AnswerPayload;

final readonly class NumberAnswer implements AnswerPayload
{
    public function __construct(public int|float $value)
    {
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }
}
