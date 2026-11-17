<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use FormsCore\DTO\Mail\SurveyReceiptMailDto;

final class SurveyReceiptMailPreview extends AbstractMailPreview
{
    public static function getSlug(): string
    {
        return 'survey-receipt';
    }

    public function getCode(): string
    {
        return 'SURVEY_RECEIPT';
    }

    public function getLabel(): string
    {
        return 'Accusé de réception';
    }

    public function getDescription(): string
    {
        return 'Envoyé immédiatement après la soumission : enregistrement définitif, lien verrouillé.';
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
            self::field('submittedAt', 'Date de soumission', self::dateValue('now'), 'datetime-local'),
        ];
    }

    public function createDto(array $data = []): object
    {
        return new SurveyReceiptMailDto(
            recipientEmail: $this->value($data, 'recipientEmail'),
            surveyTitle: $this->value($data, 'surveyTitle'),
            submittedAt: $this->date($data, 'submittedAt'),
        );
    }
}
