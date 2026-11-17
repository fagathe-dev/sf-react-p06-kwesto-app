<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use FormsCore\DTO\Mail\CreatorLowResponseMailDto;
use FormsCore\Service\LinkGenerator;

final class CreatorLowResponseMailPreview extends AbstractMailPreview
{
    public function __construct(private readonly LinkGenerator $links)
    {
    }

    public static function getSlug(): string
    {
        return 'creator-low-response';
    }

    public function getCode(): string
    {
        return 'CREATOR_LOW_RESPONSE';
    }

    public function getLabel(): string
    {
        return 'Alerte de faible participation';
    }

    public function getDescription(): string
    {
        return 'Envoyé au créateur en même temps que les relances : taux de réponse et nombre de relances parties.';
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
            self::field('responseRate', 'Taux de réponse (%)', '14.5', 'number'),
            self::field('answeredCount', 'Réponses reçues', '29', 'number'),
            self::field('invitedCount', 'Destinataires valides', '200', 'number'),
            self::field('remindersSent', 'Relances envoyées', '171', 'number'),
            self::field('expiresAt', 'Date limite', self::dateValue('+4 days 18:00'), 'datetime-local'),
        ];
    }

    public function createDto(array $data = []): object
    {
        return new CreatorLowResponseMailDto(
            creatorEmail: $this->value($data, 'creatorEmail'),
            creatorName: $this->value($data, 'creatorName'),
            surveyTitle: $this->value($data, 'surveyTitle'),
            responseRate: $this->float($data, 'responseRate'),
            answeredCount: $this->int($data, 'answeredCount'),
            invitedCount: $this->int($data, 'invitedCount'),
            remindersSent: $this->int($data, 'remindersSent'),
            recipientsUrl: $this->links->bo('/surveys/00000000-0000-7000-8000-000000000000/recipients'),
            expiresAt: $this->date($data, 'expiresAt'),
        );
    }
}
