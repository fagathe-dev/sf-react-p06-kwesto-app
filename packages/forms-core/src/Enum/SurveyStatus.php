<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Machine à états stricte du questionnaire (specs_fonc §2).
 */
enum SurveyStatus: string
{
    case Draft = 'DRAFT';
    case Validated = 'VALIDATED';
    case Active = 'ACTIVE';
    case Closed = 'CLOSED';

    /**
     * Règle d'immutabilité : dès VALIDATED, la structure (sections, questions) est figée.
     */
    public function isStructureLocked(): bool
    {
        return self::Draft !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::Validated => 'Validé',
            self::Active => 'En cours',
            self::Closed => 'Clôturé',
        };
    }
}
