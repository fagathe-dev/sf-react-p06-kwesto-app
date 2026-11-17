<?php

declare(strict_types=1);

namespace FormsCore\Service;

use FormsCore\DTO\Coerce;
use FormsCore\DTO\DisplayCondition;
use FormsCore\DTO\QuestionConfig\ChoiceConfig;
use FormsCore\Entity\Question;
use FormsCore\Enum\ConditionOperator;
use FormsCore\Enum\QuestionType;

/**
 * Affichage conditionnel (specs_fonc §3.5, specs_tech §9.4) : seule source de vérité côté serveur.
 * Sans état ; le miroir côté navigateur est forms_bo/src/pages/participant/conditionEvaluator.ts.
 *
 * Règle de conception : une question ne peut dépendre que d'une question précédente de type BOOLEAN,
 * CHOICE à choix unique (boutons radio ou liste déroulante), NUMBER ou RANGE.
 */
final class ConditionEvaluatorService
{
    public const SOURCE_RULE = 'Une condition ne peut porter que sur une question « Oui / Non », à choix unique, « Chiffres » ou « Échelle ».';

    /** La question peut-elle servir de source de condition (type et configuration) ? */
    public static function canBeSource(QuestionType $type, array $config): bool
    {
        return [] !== ConditionOperator::allowedFor($type, self::isMultipleChoice($type, $config));
    }

    /**
     * Erreurs de forme, refusées dès l'enregistrement de la structure (422) : ordre, type de source
     * (BOOLEAN ou CHOICE à choix unique seulement), opérateur incompatible, valeur du mauvais type.
     *
     * @param array<string, mixed> $sourceConfig
     *
     * @return list<string>
     */
    public function structureErrors(DisplayCondition $condition, QuestionType $sourceType, array $sourceConfig, int $sourcePosition, int $position): array
    {
        if ($sourcePosition >= $position) {
            return [\sprintf('La condition dépend de la question %d, qui doit être placée avant celle-ci.', $sourcePosition)];
        }

        $allowed = ConditionOperator::allowedFor($sourceType, self::isMultipleChoice($sourceType, $sourceConfig));
        if ([] === $allowed) {
            return [self::SOURCE_RULE];
        }

        if (!\in_array($condition->operator, $allowed, true)) {
            return ['Cet opérateur n\'est pas disponible pour le type de la question source.'];
        }

        return $this->hasValidShape($condition, $sourceType) ? [] : ['La valeur de la condition n\'est pas valide pour le type de la question source.'];
    }

    /**
     * Erreurs bloquant la validation du questionnaire : celles de structure, plus une valeur qui ne
     * correspond plus à la source (option supprimée).
     *
     * @param array<string, Question> $questionsById
     *
     * @return list<string>
     */
    public function designErrors(Question $question, array $questionsById): array
    {
        $condition = $question->getCondition();
        if (null === $condition) {
            return [];
        }

        $source = $questionsById[$condition->sourceQuestionId] ?? null;
        if (null === $source) {
            return ['La question dont dépend cette condition n\'existe plus.'];
        }

        $errors = $this->structureErrors($condition, $source->getType(), $source->getConfig(), $source->getPosition(), $question->getPosition());
        if ([] !== $errors || QuestionType::Choice !== $source->getType()) {
            return $errors;
        }

        $known = ChoiceConfig::fromArray($source->getConfig())->optionIds();
        $values = \is_array($condition->value) ? $condition->value : [(string) $condition->value];

        return [] === array_diff($values, $known) ? [] : [\sprintf('La condition vise une option qui n\'existe plus dans la question %d.', $source->getPosition())];
    }

    /**
     * @param array<string, mixed>|null $sourcePayload réponse normalisée de la source ; null = pas de réponse
     */
    public function isSatisfied(DisplayCondition $condition, QuestionType $sourceType, ?array $sourcePayload): bool
    {
        // Source sans réponse (facultative vide ou masquée) : condition fausse, quel que soit l'opérateur
        if (null === $sourcePayload) {
            return false;
        }

        $op = $condition->operator;

        return match ($sourceType) {
            QuestionType::Choice => $this->choice($op, $condition->value, (array) ($sourcePayload['optionIds'] ?? [])),
            QuestionType::Boolean => $this->equality($op, (bool) ($sourcePayload['value'] ?? false) === Coerce::bool($condition->value)),
            QuestionType::Number, QuestionType::Range => $this->compare($op, Coerce::floatOrNull($sourcePayload['value'] ?? null), Coerce::floatOrNull($condition->value)),
            default => false,
        };
    }

    /**
     * Visibilité d'une question, les questions précédentes ayant déjà été évaluées (cascade : une question
     * dont la source est masquée est masquée).
     *
     * @param array<string, Question>             $questionsById
     * @param array<string, true>                 $visible       questions précédentes visibles
     * @param array<string, array<string, mixed>> $payloads      réponses normalisées des questions précédentes
     */
    public function isVisible(Question $question, array $questionsById, array $visible, array $payloads): bool
    {
        $condition = $question->getCondition();
        if (null === $condition) {
            return true;
        }

        $source = $questionsById[$condition->sourceQuestionId] ?? null;
        if (null === $source || !isset($visible[$condition->sourceQuestionId])) {
            return false;
        }

        return $this->isSatisfied($condition, $source->getType(), $payloads[$condition->sourceQuestionId] ?? null);
    }

    /**
     * @param list<Question>                      $orderedQuestions
     * @param array<string, array<string, mixed>> $payloadsByQuestionId
     *
     * @return list<string> identifiants des questions visibles, dans l'ordre des positions
     */
    public function visibleQuestionIds(array $orderedQuestions, array $payloadsByQuestionId): array
    {
        $byId = [];
        foreach ($orderedQuestions as $question) {
            $byId[$question->getId()->toRfc4122()] = $question;
        }

        $visible = [];
        foreach ($orderedQuestions as $question) {
            if ($this->isVisible($question, $byId, $visible, $payloadsByQuestionId)) {
                $visible[$question->getId()->toRfc4122()] = true;
            }
        }

        return array_keys($visible);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function isMultipleChoice(QuestionType $type, array $config): bool
    {
        return QuestionType::Choice === $type && ChoiceConfig::fromArray($config)->isMultiple();
    }

    private function hasValidShape(DisplayCondition $condition, QuestionType $sourceType): bool
    {
        $value = $condition->value;

        return match ($sourceType) {
            QuestionType::Choice => ConditionOperator::In === $condition->operator ? \is_array($value) && [] !== $value : \is_string($value) && '' !== trim($value),
            QuestionType::Boolean => \is_bool($value),
            QuestionType::Number, QuestionType::Range => !\is_bool($value) && !\is_array($value) && null !== Coerce::floatOrNull($value),
            default => false,
        };
    }

    /** Comparaison numérique ; faux si l'une des deux valeurs n'est pas un nombre. */
    private function compare(ConditionOperator $op, ?float $answer, ?float $expected): bool
    {
        if (null === $answer || null === $expected) {
            return false;
        }

        $equal = abs($answer - $expected) < 1e-9;

        return match ($op) {
            ConditionOperator::Equals => $equal,
            ConditionOperator::NotEquals => !$equal,
            ConditionOperator::LessThan => $answer < $expected && !$equal,
            ConditionOperator::LessOrEqual => $answer < $expected || $equal,
            ConditionOperator::GreaterThan => $answer > $expected && !$equal,
            ConditionOperator::GreaterOrEqual => $answer > $expected || $equal,
            default => false,
        };
    }

    /**
     * @param string|int|float|bool|list<string> $value
     * @param array<mixed>                       $selected
     */
    private function choice(ConditionOperator $op, string|int|float|bool|array $value, array $selected): bool
    {
        $selected = array_map('strval', $selected);

        return match ($op) {
            ConditionOperator::Equals => \in_array((string) $value, $selected, true),
            ConditionOperator::NotEquals => !\in_array((string) $value, $selected, true),
            ConditionOperator::In => [] !== array_intersect(array_map('strval', (array) $value), $selected),
            default => false,
        };
    }

    private function equality(ConditionOperator $op, bool $equal): bool
    {
        return match ($op) {
            ConditionOperator::Equals => $equal,
            ConditionOperator::NotEquals => !$equal,
            default => false,
        };
    }
}
