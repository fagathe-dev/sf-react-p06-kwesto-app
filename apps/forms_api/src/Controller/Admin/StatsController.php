<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\RespondsWithServiceResult;
use FormsCore\Repository\UploadedAssetRepository;
use FormsCore\Service\ArchiveService;
use FormsCore\Service\AssetService;
use FormsCore\Service\ExportService;
use FormsCore\Service\StatsService;
use FormsCore\Service\SurveyAccess;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Module 4 — statistiques consolidées et exports (specs_fonc §7.2).
 */
#[Route('/admin/surveys/{id}', name: 'api_admin_stats_', requirements: ['id' => Requirement::UUID, 'questionId' => Requirement::UUID, 'assetId' => Requirement::UUID])]
final class StatsController extends AbstractController
{
    use RespondsWithServiceResult;

    public function __construct(
        private readonly StatsService $stats,
        private readonly SurveyAccess $access,
        private readonly ExportService $export,
        private readonly ArchiveService $archive,
        private readonly AssetService $assets,
        private readonly UploadedAssetRepository $assetRepository,
    ) {
    }

    #[Route('/stats', name: 'show', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        return $this->respond($this->stats->show($id, $request));
    }

    #[Route('/stats/questions/{questionId}/verbatims', name: 'verbatims', methods: ['GET'])]
    public function verbatims(string $id, string $questionId, Request $request): JsonResponse
    {
        return $this->respond($this->stats->verbatims($id, $questionId, $request));
    }

    #[Route('/stats/questions/{questionId}/files', name: 'files', methods: ['GET'])]
    public function files(string $id, string $questionId, Request $request): JsonResponse
    {
        return $this->respond($this->stats->files($id, $questionId, $request));
    }

    /**
     * Réponses brutes, une ligne par destinataire avec la télémétrie (CSV compatible Excel).
     */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(string $id): Response
    {
        return $this->export->csv($this->access->get($id));
    }

    /**
     * Archive .zip consolidée des fichiers déposés.
     */
    #[Route('/archive', name: 'archive', methods: ['GET'])]
    public function archive(string $id): Response
    {
        $survey = $this->access->get($id);

        if (!$this->archive->hasFiles($survey)) {
            return $this->json(['message' => 'Aucun fichier n\'a été déposé pour ce questionnaire.'], Response::HTTP_NOT_FOUND);
        }

        return $this->archive->download($survey);
    }

    /**
     * Un fichier déposé (`?inline=1` : affichage dans la galerie pour les images).
     */
    #[Route('/assets/{assetId}', name: 'asset', methods: ['GET'])]
    public function asset(string $id, string $assetId, Request $request): Response
    {
        $asset = $this->assetRepository->findOneInSurvey($this->access->get($id), $assetId);

        if (null === $asset) {
            return $this->json(['message' => 'Fichier introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->assets->download($asset, $request->query->getBoolean('inline'));
    }
}
