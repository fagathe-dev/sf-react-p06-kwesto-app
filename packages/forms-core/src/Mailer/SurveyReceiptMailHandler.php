<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use FormsCore\DTO\Mail\SurveyReceiptMailDto;
use FormsCore\Util\DateFormatter;

/**
 * @extends AbstractMailerHandler<SurveyReceiptMailDto>
 */
final class SurveyReceiptMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return SurveyReceiptMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@FormsCore/emails/survey_receipt.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return \sprintf('Vos réponses ont bien été enregistrées — %s', $dto->surveyTitle);
    }

    protected function getRecipients(object $dto): array
    {
        return [$dto->recipientEmail];
    }

    protected function getContext(object $dto): array
    {
        return ['dto' => $dto, 'submitted_at' => DateFormatter::long($dto->submittedAt)];
    }
}
