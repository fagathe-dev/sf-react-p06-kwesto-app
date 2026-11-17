<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Les 7 types de questions (specs_fonc §4). Chaque type a son handler dans FormsCore\QuestionType.
 */
enum QuestionType: string
{
    case Text = 'TEXT';
    case Number = 'NUMBER';
    case Choice = 'CHOICE';
    case Date = 'DATE';
    case File = 'FILE';
    case Boolean = 'BOOLEAN';
    case Range = 'RANGE';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Zone de texte',
            self::Number => 'Chiffres',
            self::Choice => 'Choix',
            self::Date => 'Date',
            self::File => 'Fichier',
            self::Boolean => 'Oui / Non',
            self::Range => 'Échelle',
        };
    }
}
