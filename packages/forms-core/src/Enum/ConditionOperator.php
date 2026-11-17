<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Opérateurs de l'affichage conditionnel (specs_fonc §3.5).
 */
enum ConditionOperator: string
{
    case Equals = 'EQUALS';
    case NotEquals = 'NOT_EQUALS';
    case In = 'IN';
    case LessThan = 'LESS_THAN';
    case LessOrEqual = 'LESS_OR_EQUAL';
    case GreaterThan = 'GREATER_THAN';
    case GreaterOrEqual = 'GREATER_OR_EQUAL';

    /**
     * Opérateurs autorisés selon la question source. Sources possibles :
     * - BOOLEAN : égal / différent ;
     * - CHOICE à choix unique (radio ou liste déroulante) : est / n'est pas / est l'une de ;
     * - NUMBER et RANGE : égal / différent et comparaisons (<, ≤, >, ≥).
     * Aucun opérateur (= source interdite) pour TEXT, DATE, FILE et un CHOICE à choix multiple.
     *
     * @return list<self>
     */
    public static function allowedFor(QuestionType $sourceType, bool $multipleChoice = false): array
    {
        return match ($sourceType) {
            QuestionType::Choice => $multipleChoice ? [] : [self::Equals, self::NotEquals, self::In],
            QuestionType::Boolean => [self::Equals, self::NotEquals],
            QuestionType::Number, QuestionType::Range => [self::Equals, self::NotEquals, self::LessThan, self::LessOrEqual, self::GreaterThan, self::GreaterOrEqual],
            default => [],
        };
    }
}
