<?php

declare(strict_types=1);

namespace FormsCore\DTO\AnswerPayload;

final readonly class BooleanAnswer implements AnswerPayload
{
    public function __construct(public bool $value)
    {
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }
}
