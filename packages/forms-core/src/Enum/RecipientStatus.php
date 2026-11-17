<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Suivi unitaire d'un destinataire (specs_fonc §5.4).
 */
enum RecipientStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Opened = 'OPENED';
    case Answered = 'ANSWERED';
    case Bounced = 'BOUNCED';

    /**
     * Le lien personnel permet d'afficher le formulaire (specs_fonc §6.1, cas 4).
     */
    public function canAnswer(): bool
    {
        return self::Sent === $this || self::Opened === $this;
    }

    /**
     * Statuts relancés par SURVEY_REMINDER (specs_fonc §8).
     *
     * @return list<self>
     */
    public static function remindable(): array
    {
        return [self::Sent, self::Opened];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En file d\'attente',
            self::Sent => 'Envoyé',
            self::Opened => 'Ouvert',
            self::Answered => 'Répondu',
            self::Bounced => 'Adresse rejetée',
        };
    }
}
