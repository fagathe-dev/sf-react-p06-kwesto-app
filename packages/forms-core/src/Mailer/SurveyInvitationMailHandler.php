<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use FormsCore\DTO\Mail\SurveyInvitationMailDto;
use FormsCore\Util\DateFormatter;

/**
 * @extends AbstractMailerHandler<SurveyInvitationMailDto>
 */
final class SurveyInvitationMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return SurveyInvitationMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@FormsCore/emails/survey_invitation.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return \sprintf('Vous êtes invité(e) à répondre : %s', $dto->surveyTitle);
    }

    protected function getRecipients(object $dto): array
    {
        return [$dto->recipientEmail];
    }

    protected function getContext(object $dto): array
    {
        return ['dto' => $dto, 'expires_at' => DateFormatter::long($dto->expiresAt)];
    }
}
