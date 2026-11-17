<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use FormsCore\DTO\Mail\CreatorSurveyClosedMailDto;
use FormsCore\Enum\CloseReason;
use FormsCore\Service\LinkGenerator;

final class CreatorSurveyClosedMailPreview extends AbstractMailPreview
{
    public function __construct(private readonly LinkGenerator $links)
    {
    }

    public static function getSlug(): string
    {
        return 'creator-survey-closed';
    }

    public function getCode(): string
    {
        return 'CREATOR_SURVEY_CLOSED';
    }

    public function getLabel(): string
    {
        return 'Questionnaire clôturé';
    }

    public function getDescription(): string
    {
        return 'Envoyé au créateur à la clôture (100 % de réponses, date limite ou clôture manuelle).';
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
            self::field('closeReason', 'Motif de clôture', CloseReason::DeadlineReached->label()),
            self::field('finalRate', 'Taux final (%)', '68.5', 'number'),
            self::field('answeredCount', 'Réponses reçues', '137', 'number'),
            self::field('invitedCount', 'Destinataires valides', '200', 'number'),
        ];
    }

    public function createDto(array $data = []): object
    {
        return new CreatorSurveyClosedMailDto(
            creatorEmail: $this->value($data, 'creatorEmail'),
            creatorName: $this->value($data, 'creatorName'),
            surveyTitle: $this->value($data, 'surveyTitle'),
            closeReason: $this->value($data, 'closeReason'),
            finalRate: $this->float($data, 'finalRate'),
            answeredCount: $this->int($data, 'answeredCount'),
            invitedCount: $this->int($data, 'invitedCount'),
            statsUrl: $this->links->bo('/surveys/00000000-0000-7000-8000-000000000000/stats'),
        );
    }
}
