<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\RespondsWithServiceResult;
use FormsCore\Service\SearchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Recherche plein texte du dashboard : ?q=terme (specs_tech §3.5).
 */
final class SearchController extends AbstractController
{
    use RespondsWithServiceResult;

    #[Route('/admin/search', name: 'api_admin_search', methods: ['GET'])]
    public function __invoke(Request $request, SearchService $search): JsonResponse
    {
        return $this->respond($search->search($request));
    }
}
