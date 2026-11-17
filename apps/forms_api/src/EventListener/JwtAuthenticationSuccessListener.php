<?php

declare(strict_types=1);

namespace App\EventListener;

use FormsCore\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Ajoute l'utilisateur à la réponse de connexion : { token, user }.
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_success')]
final class JwtAuthenticationSuccessListener
{
    public function __construct(private readonly NormalizerInterface $normalizer)
    {
    }

    public function __invoke(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();

        if ($user instanceof User) {
            $event->setData($event->getData() + ['user' => $this->normalizer->normalize($user, 'json', ['groups' => ['user.read']])]);
        }
    }
}
