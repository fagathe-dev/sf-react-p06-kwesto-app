<?php

declare(strict_types=1);

namespace FormsCore\MessageHandler;

use FormsCore\Message\SendInvitationMessage;
use FormsCore\Repository\SurveyRecipientRepository;
use FormsCore\Service\InvitationSender;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class SendInvitationMessageHandler
{
    public function __construct(
        private readonly SurveyRecipientRepository $recipientRepository,
        private readonly InvitationSender $invitationSender,
    ) {
    }

    public function __invoke(SendInvitationMessage $message): void
    {
        if (!Uuid::isValid($message->recipientId)) {
            return;
        }

        $recipient = $this->recipientRepository->find(Uuid::fromString($message->recipientId));

        // Destinataire supprimé entre-temps : rien à envoyer. Les échecs temporaires sont repris
        // par le rattrapage SMTP (toutes les 15 min), pas par les retries de Messenger.
        if (null !== $recipient) {
            $this->invitationSender->send($recipient);
        }
    }
}
