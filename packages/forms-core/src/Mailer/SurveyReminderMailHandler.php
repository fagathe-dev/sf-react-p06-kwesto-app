<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use FormsCore\DTO\Mail\SurveyReminderMailDto;
use FormsCore\Util\DateFormatter;

/**
 * @extends AbstractMailerHandler<SurveyReminderMailDto>
 */
final class SurveyReminderMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return SurveyReminderMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@FormsCore/emails/survey_reminder.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return \sprintf('Rappel : votre réponse est attendue — %s', $dto->surveyTitle);
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
