<?php

declare(strict_types=1);

namespace FormsCore\DTO\AnswerPayload;

final readonly class TextAnswer implements AnswerPayload
{
    public function __construct(public string $value)
    {
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }
}
