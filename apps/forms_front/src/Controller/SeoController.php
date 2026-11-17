<?php

declare(strict_types=1);

namespace App\Controller;

use App\Seo\PageCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routes techniques du référencement (specs_fonc §11.4) : sitemap.xml et robots.txt, générés dynamiquement.
 * Les URL sont construites sur l'URL publique du site (DEFAULT_URI), jamais sur l'hôte de la requête.
 */
final class SeoController extends AbstractController
{
    public function __construct(
        private readonly PageCatalog $pages,
        #[Autowire('%kernel.project_dir%/templates')]
        private readonly string $templatesDir,
        private readonly string $siteUrl,
    ) {
    }

    /**
     * Toutes les pages publiques, avec la date de dernière modification de leur gabarit et leur priorité.
     */
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'], format: 'xml')]
    public function sitemap(): Response
    {
        $urls = [];
        foreach ($this->pages->all() as $route => $page) {
            $file = $this->templatesDir.'/'.$page['template'];
            $urls[] = [
                'loc' => $this->publicUrl($route),
                'lastmod' => date('Y-m-d', is_file($file) ? (int) filemtime($file) : time()),
                'changefreq' => $page['changefreq'],
                'priority' => $page['priority'],
            ];
        }

        $response = $this->render('seo/sitemap.xml.twig', ['urls' => $urls]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic()->setMaxAge(3600);

        return $response;
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'], format: 'txt')]
    public function robots(): Response
    {
        $response = $this->render('seo/robots.txt.twig', ['sitemap' => $this->publicUrl('app_sitemap')]);
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->setPublic()->setMaxAge(3600);

        return $response;
    }

    private function publicUrl(string $route): string
    {
        return rtrim($this->siteUrl, '/').$this->generateUrl($route);
    }
}
