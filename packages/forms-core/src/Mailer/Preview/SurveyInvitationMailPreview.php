<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use FormsCore\DTO\Mail\SurveyInvitationMailDto;
use FormsCore\Service\LinkGenerator;

final class SurveyInvitationMailPreview extends AbstractMailPreview
{
    public function __construct(private readonly LinkGenerator $links)
    {
    }

    public static function getSlug(): string
    {
        return 'survey-invitation';
    }

    public function getCode(): string
    {
        return 'SURVEY_INVITATION';
    }

    public function getLabel(): string
    {
        return 'Invitation à répondre';
    }

    public function getDescription(): string
    {
        return 'Envoyé à chaque destinataire au lancement de la campagne, avec son lien personnel.';
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
            self::field('surveyDescription', 'Description', "Votre avis compte : ce baromètre annuel nous aide à améliorer le quotidien de chacun.\nVos réponses sont traitées de façon confidentielle.", 'textarea'),
            self::field('questionsCount', 'Nombre de questions', '12', 'number'),
            self::field('expiresAt', 'Date limite', self::dateValue('+10 days 18:00'), 'datetime-local'),
        ];
    }

    public function createDto(array $data = []): object
    {
        return new SurveyInvitationMailDto(
            recipientEmail: $this->value($data, 'recipientEmail'),
            surveyTitle: $this->value($data, 'surveyTitle'),
            surveyDescription: $this->value($data, 'surveyDescription'),
            questionsCount: $this->int($data, 'questionsCount'),
            surveyUrl: $this->links->participantUrlForToken(str_repeat('a1b2c3d4', 8)),
            expiresAt: $this->date($data, 'expiresAt'),
        );
    }
}
