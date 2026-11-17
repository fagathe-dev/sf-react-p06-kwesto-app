<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use FormsCore\DTO\Mail\ComponentsShowcaseMailDto;

/**
 * Vitrine du layout et des composants d'e-mail de core-php (@CorePhp/layout, @CorePhp/components/*).
 *
 * @extends AbstractMailerHandler<ComponentsShowcaseMailDto>
 */
final class ComponentsShowcaseMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public static function getDtoClass(): string
    {
        return ComponentsShowcaseMailDto::class;
    }

    protected function getTemplate(): string
    {
        return '@FormsCore/emails/components_showcase.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return 'Composants d\'e-mail core-php';
    }

    protected function getRecipients(object $dto): array
    {
        return [$dto->recipientEmail];
    }
}
