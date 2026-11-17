<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Variante d'affichage d'une question CHOICE (specs_fonc §4.3).
 */
enum ChoiceWidget: string
{
    case Radio = 'radio';
    case Checkbox = 'checkbox';
    case Select = 'select';

    /**
     * `multiple` est dérivé du widget : true pour checkbox, false pour radio et select.
     */
    public function isMultiple(): bool
    {
        return self::Checkbox === $this;
    }
}
