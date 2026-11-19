<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Le front envoie les mots de passe encodés en base64 (UTF-8 → base64) : décodage avant la vérification du
 * mot de passe (CheckCredentialsListener, priorité 0), sur le seul firewall de connexion.
 * Un mot de passe qui n'est pas du base64 valide est refusé comme un mot de passe erroné.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 512, dispatcher: 'security.event_dispatcher.login')]
final class Base64PasswordListener
{
    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(PasswordCredentials::class)) {
            return;
        }

        /** @var PasswordCredentials $credentials */
        $credentials = $passport->getBadge(PasswordCredentials::class);
        if ($credentials->isResolved()) {
            return;
        }

        $decoded = base64_decode($credentials->getPassword(), true);
        if (false === $decoded || '' === $decoded || !mb_check_encoding($decoded, 'UTF-8')) {
            throw new BadCredentialsException('Le mot de passe doit être encodé en base64.');
        }

        // addBadge remplace le badge de même classe
        $passport->addBadge(new PasswordCredentials($decoded));
    }
}
