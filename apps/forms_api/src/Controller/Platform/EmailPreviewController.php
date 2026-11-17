<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Controller\RespondsWithServiceResult;
use FormsCore\Entity\User;
use FormsCore\Service\EmailPreviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace Administration — prévisualisation des templates d'e-mails (specs_fonc §10.1).
 */
#[Route('/admin/platform/emails', name: 'api_platform_emails_', requirements: ['slug' => '[a-z0-9-]+'])]
#[IsGranted('ROLE_ADMIN')]
final class EmailPreviewController extends AbstractController
{
    use RespondsWithServiceResult;

    public function __construct(private readonly EmailPreviewService $previews)
    {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->respond($this->previews->list());
    }

    /**
     * HTML rendu (GET : jeu de données par défaut ; POST { data } : jeu personnalisé),
     * affiché par le back-office dans une iframe sandbox.
     */
    #[Route('/{slug}', name: 'preview', methods: ['GET', 'POST'])]
    public function preview(string $slug, Request $request): JsonResponse
    {
        $data = $request->isMethod('POST') ? (array) ($request->toArray()['data'] ?? []) : [];
        $rendered = $this->previews->render($slug, $data);

        if (null === $rendered) {
            return $this->json(['message' => 'Aperçu introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($rendered);
    }

    /**
     * Envoi d'un e-mail de test à l'adresse de l'administrateur connecté.
     */
    #[Route('/{slug}/test', name: 'test', methods: ['POST'])]
    public function test(string $slug, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = '' === $request->getContent() ? [] : (array) ($request->toArray()['data'] ?? []);

        return $this->respond($this->previews->sendTest($slug, $data, $user->getEmail()));
    }
}
