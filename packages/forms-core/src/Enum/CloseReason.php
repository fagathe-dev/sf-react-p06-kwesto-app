<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Motif de clôture d'un questionnaire (specs_fonc §7.1).
 */
enum CloseReason: string
{
    case AllAnswered = 'ALL_ANSWERED';
    case DeadlineReached = 'DEADLINE_REACHED';
    case Manual = 'MANUAL';

    public function label(): string
    {
        return match ($this) {
            self::AllAnswered => 'Tous les destinataires ont répondu',
            self::DeadlineReached => 'La date limite de réponse est atteinte',
            self::Manual => 'Clôture manuelle par le créateur',
        };
    }
}
