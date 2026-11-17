<?php

declare(strict_types=1);

namespace FormsCore\DTO\AnswerPayload;

final readonly class DateAnswer implements AnswerPayload
{
    /**
     * @param string $value date au format YYYY-MM-DD
     */
    public function __construct(public string $value)
    {
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }
}
