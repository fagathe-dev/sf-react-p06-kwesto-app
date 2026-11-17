<?php

declare(strict_types=1);

namespace App\Controller;

use App\Seo\PageCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages statiques du site vitrine (specs_tech §5.2), rendues côté serveur.
 */
final class PageController extends AbstractController
{
    public function __construct(private readonly PageCatalog $pages)
    {
    }

    #[Route('', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->page('app_home');
    }

    #[Route('/fonctionnalites', name: 'app_features', methods: ['GET'])]
    public function features(): Response
    {
        return $this->page('app_features');
    }

    #[Route('/cas-usage', name: 'app_use_cases', methods: ['GET'])]
    public function useCases(): Response
    {
        return $this->page('app_use_cases');
    }

    #[Route('/tarifs', name: 'app_pricing', methods: ['GET'])]
    public function pricing(): Response
    {
        return $this->page('app_pricing');
    }

    #[Route('/mentions-legales', name: 'app_legal', methods: ['GET'])]
    public function legal(): Response
    {
        return $this->page('app_legal');
    }

    #[Route('/confidentialite', name: 'app_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->page('app_privacy');
    }

    private function page(string $route): Response
    {
        $page = $this->pages->get($route);

        $response = $this->render($page['template'], ['seo' => $page]);
        // Pages statiques : cache navigateur et proxy de 10 minutes
        $response->setPublic()->setMaxAge(600)->setSharedMaxAge(600);

        return $response;
    }
}
