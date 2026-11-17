<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use FormsCore\DTO\Mail\ComponentsShowcaseMailDto;

final class ComponentsShowcaseMailPreview extends AbstractMailPreview
{
    public static function getSlug(): string
    {
        return 'core-php-components';
    }

    public function getCode(): string
    {
        return 'CORE_PHP_COMPONENTS';
    }

    public function getLabel(): string
    {
        return 'Layout et composants core-php';
    }

    public function getDescription(): string
    {
        return 'Templates prédéfinis de core-php : layout, textes, boutons, alertes, détails, code, liste, box et carte.';
    }

    public function getAudience(): string
    {
        return 'core-php';
    }

    public function getFields(): array
    {
        return [
            self::field('recipientEmail', 'Destinataire', self::RECIPIENT_EMAIL, 'email'),
            self::field('headline', 'Titre', 'Composants d\'e-mail'),
        ];
    }

    public function createDto(array $data = []): object
    {
        return new ComponentsShowcaseMailDto(
            recipientEmail: $this->value($data, 'recipientEmail'),
            headline: $this->value($data, 'headline'),
        );
    }
}
