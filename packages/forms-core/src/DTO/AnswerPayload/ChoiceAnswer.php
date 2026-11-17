<?php

declare(strict_types=1);

namespace FormsCore\DTO\AnswerPayload;

final readonly class ChoiceAnswer implements AnswerPayload
{
    /**
     * @param list<string> $optionIds identifiants des options sélectionnées
     */
    public function __construct(public array $optionIds)
    {
    }

    public function toArray(): array
    {
        return ['optionIds' => $this->optionIds];
    }
}
