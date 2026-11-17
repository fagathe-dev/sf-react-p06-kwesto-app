<?php

declare(strict_types=1);

namespace App\Controller;

use FormsCore\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/auth', name: 'api_auth_')]
final class AuthController extends AbstractController
{
    /**
     * Connexion : interceptée par le firewall « login » (json_login + Lexik), qui renvoie
     * { token, user }. La route n'existe que pour être résolue par le routeur.
     */
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Cette route est gérée par le firewall "login".');
    }

    /**
     * Profil de l'utilisateur connecté (restauration de session du back-office).
     */
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($user, context: ['groups' => ['user.read']]);
    }
}
