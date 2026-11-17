<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Controller\RespondsWithServiceResult;
use FormsCore\Service\AssetService;
use FormsCore\Service\ParticipantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Module 3 — formulaire participant, sans compte (specs_fonc §6). Le jeton fait office d'accès.
 */
#[Route('/public/surveys/{token}', name: 'api_public_survey_', requirements: ['token' => '[a-f0-9]{64}', 'questionId' => Requirement::UUID, 'assetId' => Requirement::UUID])]
final class ParticipantController extends AbstractController
{
    use RespondsWithServiceResult;

    public function __construct(
        private readonly ParticipantService $participant,
        private readonly AssetService $assets,
        #[Autowire(service: 'limiter.participant')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    /**
     * Ouverture du lien : questionnaire (état OPEN) ou écran d'erreur (INVALID, CLOSED, ANSWERED).
     */
    #[Route('', name: 'open', methods: ['GET'])]
    public function open(string $token): JsonResponse
    {
        return $this->noStore($this->respond($this->participant->open($token)));
    }

    #[Route('/submit', name: 'submit', methods: ['POST'])]
    public function submit(string $token, Request $request): JsonResponse
    {
        if (null !== $tooMany = $this->throttle($token, $request)) {
            return $tooMany;
        }

        return $this->noStore($this->respond($this->participant->submit($token, $request)));
    }

    /**
     * Brouillon : réponses en cours, enregistrées au fil de la saisie pour reprendre plus tard (specs_fonc §6.5).
     * Pas de limitation de débit ici : l'enregistrement automatique est fréquent et ne doit jamais bloquer l'envoi.
     */
    #[Route('/draft', name: 'save_draft', methods: ['PUT'])]
    public function saveDraft(string $token, Request $request): JsonResponse
    {
        $recipient = $this->participant->resolveAnswering($token);
        if (null === $recipient) {
            return $this->noStore($this->json(['message' => 'Ce lien ne permet plus d\'enregistrer de réponses.'], Response::HTTP_FORBIDDEN));
        }

        return $this->noStore($this->respond($this->participant->saveDraft($recipient, $request)));
    }

    /**
     * « Recommencer » : brouillon effacé et fichiers déjà déposés supprimés.
     */
    #[Route('/draft', name: 'discard_draft', methods: ['DELETE'])]
    public function discardDraft(string $token): JsonResponse
    {
        $recipient = $this->participant->resolveAnswering($token);
        if (null === $recipient) {
            return $this->noStore($this->json(['message' => 'Ce lien ne permet plus de modifier les réponses.'], Response::HTTP_FORBIDDEN));
        }

        $this->assets->deleteAllPending($recipient);

        return $this->noStore($this->respond($this->participant->discardDraft($recipient)));
    }

    /**
     * Dépôt d'un fichier (multipart, champ `file`) pour une question FILE.
     */
    #[Route('/questions/{questionId}/files', name: 'upload', methods: ['POST'])]
    public function upload(string $token, string $questionId, Request $request): JsonResponse
    {
        if (null !== $tooMany = $this->throttle($token, $request)) {
            return $tooMany;
        }

        $recipient = $this->participant->resolveAnswering($token);
        if (null === $recipient) {
            return $this->json(['message' => 'Ce lien ne permet plus de déposer de fichier.'], Response::HTTP_FORBIDDEN);
        }

        return $this->respond($this->assets->upload($recipient, $questionId, $request));
    }

    #[Route('/files/{assetId}', name: 'delete_file', methods: ['DELETE'])]
    public function deleteFile(string $token, string $assetId): JsonResponse
    {
        $recipient = $this->participant->resolveAnswering($token);
        if (null === $recipient) {
            return $this->json(['message' => 'Ce lien ne permet plus de modifier les fichiers.'], Response::HTTP_FORBIDDEN);
        }

        return $this->respond($this->assets->deletePending($recipient, $assetId));
    }

    private function throttle(string $token, Request $request): ?JsonResponse
    {
        $limit = $this->limiter->create($token.'|'.$request->getClientIp())->consume();

        return $limit->isAccepted()
            ? null
            : $this->json(['message' => 'Trop de requêtes. Merci de patienter quelques minutes.'], Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * Le lien personnel et les réponses ne doivent jamais être mis en cache par un proxy.
     */
    private function noStore(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
