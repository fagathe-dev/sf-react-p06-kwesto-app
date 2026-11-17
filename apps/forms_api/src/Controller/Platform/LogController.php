<?php

declare(strict_types=1);

namespace App\Controller\Platform;

use App\Controller\RespondsWithServiceResult;
use FormsCore\Service\LogViewerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Espace Administration — inspection des journaux (specs_fonc §10.2).
 * Filtres : channel, level, from, to, q, page.
 */
#[IsGranted('ROLE_ADMIN')]
final class LogController extends AbstractController
{
    use RespondsWithServiceResult;

    #[Route('/admin/platform/logs', name: 'api_platform_logs', methods: ['GET'])]
    public function __invoke(Request $request, LogViewerService $logs): JsonResponse
    {
        return $this->respond($logs->search($request));
    }
}
