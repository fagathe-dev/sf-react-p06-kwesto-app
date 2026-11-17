<?php

declare(strict_types=1);

namespace FormsCore\DTO\AnswerPayload;

/**
 * Contrat polymorphe d'une réponse validée, stocké en JSON dans SurveyAnswer::$payload.
 *
 * | Type   | Classe       | JSON                              |
 * |--------|--------------|-----------------------------------|
 * | TEXT   | TextAnswer   | { "value": "…" }                  |
 * | NUMBER | NumberAnswer | { "value": 12.5 }                 |
 * | CHOICE | ChoiceAnswer | { "optionIds": ["a", "b"] }       |
 * | DATE   | DateAnswer   | { "value": "2026-11-13" }         |
 * | FILE   | FileAnswer   | { "assetIds": ["<uuid>", "…"] }   |
 */
interface AnswerPayload
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
