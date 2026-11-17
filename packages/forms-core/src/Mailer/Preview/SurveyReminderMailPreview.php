<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use FormsCore\DTO\Mail\SurveyReminderMailDto;
use FormsCore\Service\LinkGenerator;

final class SurveyReminderMailPreview extends AbstractMailPreview
{
    public function __construct(private readonly LinkGenerator $links)
    {
    }

    public static function getSlug(): string
    {
        return 'survey-reminder';
    }

    public function getCode(): string
    {
        return 'SURVEY_REMINDER';
    }

    public function getLabel(): string
    {
        return 'Relance avant échéance';
    }

    public function getDescription(): string
    {
        return 'Rappel unique entre J-5 et J-4, si le taux de réponse est ≤ 20 % et le sondage lancé depuis au moins 48 h.';
    }

    public function getAudience(): string
    {
        return 'Participant';
    }

    public function getFields(): array
    {
        return [
            self::field('recipientEmail', 'Destinataire', self::RECIPIENT_EMAIL, 'email'),
            self::field('surveyTitle', 'Titre du questionnaire', self::SURVEY_TITLE),
            self::field('expiresAt', 'Date limite', self::dateValue('+4 days 18:00'), 'datetime-local'),
        ];
    }

    public function createDto(array $data = []): object
    {
        return new SurveyReminderMailDto(
            recipientEmail: $this->value($data, 'recipientEmail'),
            surveyTitle: $this->value($data, 'surveyTitle'),
            surveyUrl: $this->links->participantUrlForToken(str_repeat('a1b2c3d4', 8)),
            expiresAt: $this->date($data, 'expiresAt'),
        );
    }
}
