<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Fagathe\CorePhp\Mailer\AbstractMailerHandler;
use FormsCore\DTO\Mail\ContactRequestMailDto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * Message du formulaire de contact du site vitrine : envoyé à CONTACT_RECIPIENT_EMAIL,
 * avec l'expéditeur du message en Reply-To.
 *
 * @extends AbstractMailerHandler<ContactRequestMailDto>
 */
final class ContactRequestMailHandler extends AbstractMailerHandler implements MailHandlerInterface
{
    public function __construct(
        #[Autowire(param: 'forms_core.contact.recipient')]
        private readonly ?string $contactRecipient,
    ) {
    }

    public static function getDtoClass(): string
    {
        return ContactRequestMailDto::class;
    }

    public function isConfigured(): bool
    {
        return null !== $this->contactRecipient && '' !== $this->contactRecipient;
    }

    protected function getTemplate(): string
    {
        return '@FormsCore/emails/contact_request.html.twig';
    }

    protected function getSubject(object $dto): string
    {
        return \sprintf('[Contact] %s', $dto->subject);
    }

    /**
     * Sans CONTACT_RECIPIENT_EMAIL, une adresse de démonstration permet l'aperçu ; ContactService
     * vérifie isConfigured() avant tout envoi réel.
     */
    protected function getRecipients(object $dto): array
    {
        return [$this->isConfigured() ? (string) $this->contactRecipient : 'contact@kwesto.localhost'];
    }

    protected function getReplyTo(object $dto): ?Address
    {
        return new Address($dto->email, $dto->name);
    }

    protected function getContext(object $dto): array
    {
        return ['dto' => $dto, 'received_at' => new \DateTimeImmutable()];
    }
}
