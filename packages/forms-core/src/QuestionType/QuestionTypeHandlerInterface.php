<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\DTO\AnswerPayload\AnswerPayload;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Exception\InvalidAnswerException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Comportement d'un type de question : configuration, validation d'une réponse, recherche,
 * statistiques et export (specs_fonc §4).
 *
 * Open/Closed : un type = une implémentation, découverte par QuestionTypeRegistry. Aucun `match`
 * sur le type ailleurs dans le code métier.
 */
#[AutoconfigureTag(self::TAG)]
interface QuestionTypeHandlerInterface
{
    public const TAG = 'forms_core.question_type';

    /**
     * Valeur de l'enum QuestionType prise en charge (clé du locator de QuestionTypeRegistry).
     */
    public static function getTypeValue(): string;

    /**
     * Configuration normalisée à stocker : types forcés, valeurs par défaut, clés inconnues retirées.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function normalizeConfig(array $raw): array;

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string> erreurs qui empêchent de valider le questionnaire
     */
    public function validateConfig(array $config): array;

    /**
     * La valeur reçue équivaut à « pas de réponse » (champ vide, aucune option, aucun fichier).
     */
    public function isEmptyAnswer(mixed $raw): bool;

    /**
     * Valide et normalise la valeur saisie par le participant.
     *
     * @throws InvalidAnswerException message affichable au participant
     */
    public function validateAnswer(Question $question, mixed $raw, SurveyRecipient $recipient): AnswerPayload;

    /**
     * Texte recopié dans SurveyAnswer::$searchText (index FULLTEXT), null si le type n'est pas cherchable.
     *
     * @param array<string, mixed> $payload
     */
    public function extractSearchText(array $payload): ?string;

    /**
     * Statistiques de la question (specs_fonc §4, ligne « Statistiques » de chaque type).
     *
     * @param list<array<string, mixed>> $payloads payloads de toutes les réponses à la question
     *
     * @return array<string, mixed>
     */
    public function computeStats(Question $question, array $payloads): array;

    /**
     * Valeur lisible pour l'export CSV / Excel des réponses brutes.
     *
     * @param array<string, mixed> $payload
     */
    public function exportValue(Question $question, array $payload): string;
}
