<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use FormsCore\DTO\Mail\CreatorBounceAlertMailDto;
use Symfony\Component\Mime\Address;

/**
 * @extends AbstractMailerHandler<CreatorBounceAlertMailDto>
 */
final class CreatorBounceAlertMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return CreatorBounceAlertMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@FormsCore/emails/creator_bounce_alert.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return \sprintf('Adresse rejetée : %s', $dto->surveyTitle);
    }

    protected function getRecipients(object $dto): array
    {
        return [new Address($dto->creatorEmail, $dto->creatorName)];
    }
}
