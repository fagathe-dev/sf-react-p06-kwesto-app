<?php

declare(strict_types=1);

namespace FormsCore\DTO\QuestionConfig;

/**
 * Contrat polymorphe de configuration d'une question, stocké en JSON dans Question::$config.
 * Une implémentation par type (specs_fonc §4) : TextConfig, NumberConfig, ChoiceConfig, DateConfig, FileConfig, BooleanConfig, RangeConfig.
 */
interface QuestionConfig
{
    /**
     * @param array<string, mixed> $data configuration brute (builder ou base de données)
     */
    public static function fromArray(array $data): static;

    /**
     * @return array<string, mixed> forme JSON stockée et renvoyée par l'API
     */
    public function toArray(): array;

    /**
     * @return list<string> erreurs qui empêchent de valider le questionnaire
     */
    public function violations(): array;
}
