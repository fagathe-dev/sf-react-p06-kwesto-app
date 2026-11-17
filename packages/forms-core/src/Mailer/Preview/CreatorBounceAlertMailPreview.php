<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use FormsCore\DTO\Mail\CreatorBounceAlertMailDto;
use FormsCore\Service\LinkGenerator;

final class CreatorBounceAlertMailPreview extends AbstractMailPreview
{
    public function __construct(private readonly LinkGenerator $links)
    {
    }

    public static function getSlug(): string
    {
        return 'creator-bounce-alert';
    }

    public function getCode(): string
    {
        return 'CREATOR_BOUNCE_ALERT';
    }

    public function getLabel(): string
    {
        return 'Alerte d\'adresse rejetée';
    }

    public function getDescription(): string
    {
        return 'Envoyé au créateur quand une invitation est rejetée définitivement (adresse exclue des 100 %).';
    }

    public function getAudience(): string
    {
        return 'Créateur';
    }

    public function getFields(): array
    {
        return [
            self::field('creatorEmail', 'Destinataire', self::CREATOR_EMAIL, 'email'),
            self::field('creatorName', 'Nom du créateur', self::CREATOR_NAME),
            self::field('surveyTitle', 'Titre du questionnaire', self::SURVEY_TITLE),
            self::field('bouncedEmail', 'Adresse rejetée', 'ancien.collaborateur@example.com', 'email'),
        ];
    }

    public function createDto(array $data = []): object
    {
        return new CreatorBounceAlertMailDto(
            creatorEmail: $this->value($data, 'creatorEmail'),
            creatorName: $this->value($data, 'creatorName'),
            surveyTitle: $this->value($data, 'surveyTitle'),
            bouncedEmail: $this->value($data, 'bouncedEmail'),
            recipientsUrl: $this->links->bo('/surveys/00000000-0000-7000-8000-000000000000/recipients'),
        );
    }
}
