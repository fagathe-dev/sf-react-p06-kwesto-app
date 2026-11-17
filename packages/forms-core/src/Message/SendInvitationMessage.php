<?php

declare(strict_types=1);

namespace FormsCore\Message;

/**
 * Envoi asynchrone de l'invitation d'un destinataire (specs_fonc §5.3, étape 3).
 * À router vers un transport Messenger dans l'application hôte (forms_api : transport `async`).
 */
final readonly class SendInvitationMessage
{
    public function __construct(public string $recipientId)
    {
    }
}
