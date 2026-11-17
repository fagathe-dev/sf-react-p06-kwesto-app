<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use Fagathe\CorePhp\Mailer\Exception\MailerExceptionInterface;
use FormsCore\DTO\Mail\ContactRequestMailDto;
use FormsCore\Enum\LogChannel;
use FormsCore\Mailer\ContactRequestMailHandler;
use FormsCore\Mailer\MailDispatcher;
use FormsCore\Trait\ChannelLoggerTrait;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Formulaire de contact du site vitrine (specs_tech §5.2) : validation puis transmission à
 * CONTACT_RECIPIENT_EMAIL via le mailer de core-php.
 */
final class ContactService
{
    use ChannelLoggerTrait;

    public function __construct(
        private readonly ValidatorInterface $validator,
        private readonly MailDispatcher $mailDispatcher,
        private readonly ContactRequestMailHandler $handler,
    ) {
    }

    /**
     * @return array{sent: bool, errors: array<string, string>}
     */
    public function send(ContactRequestMailDto $dto): array
    {
        $errors = [];
        foreach ($this->validator->validate($dto) as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        if ([] !== $errors) {
            return ['sent' => false, 'errors' => $errors];
        }

        if (!$this->handler->isConfigured()) {
            $this->logTo(LogChannel::Mailer, LoggerLevelEnum::Error, 'Formulaire de contact : CONTACT_RECIPIENT_EMAIL non défini.', 'contact.not_configured');

            return ['sent' => false, 'errors' => []];
        }

        try {
            $this->mailDispatcher->dispatch($dto);
        } catch (MailerExceptionInterface) {
            // Déjà journalisé par AbstractMailerHandler
            return ['sent' => false, 'errors' => []];
        }

        return ['sent' => true, 'errors' => []];
    }
}
