<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Rôles du dashboard (specs_fonc §1). Le participant n'a pas de compte.
 */
enum UserRole: string
{
    case User = 'ROLE_USER';
    case Admin = 'ROLE_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Créateur',
            self::Admin => 'Administrateur',
        };
    }
}
