<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use FormsCore\DTO\Mail\CreatorLowResponseMailDto;
use FormsCore\Util\DateFormatter;
use Symfony\Component\Mime\Address;

/**
 * @extends AbstractMailerHandler<CreatorLowResponseMailDto>
 */
final class CreatorLowResponseMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return CreatorLowResponseMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@FormsCore/emails/creator_low_response.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return \sprintf('Faible participation : %s', $dto->surveyTitle);
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->creatorEmail, $dto->creatorName)];
    }

    protected function getContext(object $dto): array
    {
        return ['dto' => $dto, 'expires_at' => DateFormatter::long($dto->expiresAt)];
    }
}
