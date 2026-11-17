<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use FormsCore\DTO\Mail\CreatorSurveyClosedMailDto;
use Symfony\Component\Mime\Address;

/**
 * @extends AbstractMailerHandler<CreatorSurveyClosedMailDto>
 */
final class CreatorSurveyClosedMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return CreatorSurveyClosedMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@FormsCore/emails/creator_survey_closed.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return \sprintf('Questionnaire clôturé : %s', $dto->surveyTitle);
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->creatorEmail, $dto->creatorName)];
    }
}
