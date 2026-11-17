<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Pages publiques du site vitrine (specs_fonc §11.2) : métadonnées SEO et entrées du sitemap.
 * Une page = une entrée : le contrôleur, le gabarit maître et sitemap.xml lisent cette même source.
 */
final class PageCatalog
{
    /**
     * @var array<string, array{template: string, title: string, description: string, priority: string, changefreq: string}>
     */
    private const PAGES = [
        'app_home' => [
            'template' => 'pages/home.html.twig',
            'title' => 'kwesto — Questionnaires sur invitation et statistiques consolidées',
            'description' => 'Créez des questionnaires, diffusez-les par lien personnel unique, relancez automatiquement et analysez des statistiques consolidées. Sans compte pour vos participants.',
            'priority' => '1.0',
            'changefreq' => 'weekly',
        ],
        'app_features' => [
            'template' => 'pages/features.html.twig',
            'title' => 'Fonctionnalités — 7 types de questions, lien unique, relances | kwesto',
            'description' => 'Texte, chiffres, choix, date et fichier ; lien personnel à usage unique ; relances automatiques ; statistiques figées à la clôture et exports CSV et ZIP.',
            'priority' => '0.9',
            'changefreq' => 'monthly',
        ],
        'app_use_cases' => [
            'template' => 'pages/use_cases.html.twig',
            'title' => 'Cas d\'usage — enquêtes internes, dossiers, évaluations de formation | kwesto',
            'description' => 'Baromètres internes, collecte de dossiers et de pièces jointes, évaluations post-formation : comment les équipes utilisent kwesto.',
            'priority' => '0.8',
            'changefreq' => 'monthly',
        ],
        'app_pricing' => [
            'template' => 'pages/pricing.html.twig',
            'title' => 'Tarifs et questions fréquentes | kwesto',
            'description' => 'Des offres simples, sans engagement, et les réponses aux questions les plus fréquentes sur kwesto.',
            'priority' => '0.8',
            'changefreq' => 'monthly',
        ],
        'app_contact' => [
            'template' => 'pages/contact.html.twig',
            'title' => 'Contact | kwesto',
            'description' => 'Une question sur kwesto, une démonstration, un besoin spécifique ? Écrivez-nous.',
            'priority' => '0.6',
            'changefreq' => 'yearly',
        ],
        'app_legal' => [
            'template' => 'pages/legal.html.twig',
            'title' => 'Mentions légales | kwesto',
            'description' => 'Mentions légales du site kwesto : éditeur, hébergement, propriété intellectuelle.',
            'priority' => '0.2',
            'changefreq' => 'yearly',
        ],
        'app_privacy' => [
            'template' => 'pages/privacy.html.twig',
            'title' => 'Politique de confidentialité et RGPD | kwesto',
            'description' => 'Données collectées, finalités, durées de conservation, anonymisation de la télémétrie et exercice de vos droits.',
            'priority' => '0.3',
            'changefreq' => 'yearly',
        ],
    ];

    /**
     * @return array{template: string, title: string, description: string, priority: string, changefreq: string}
     */
    public function get(string $route): array
    {
        return self::PAGES[$route] ?? throw new \InvalidArgumentException(\sprintf('Page inconnue : "%s".', $route));
    }

    /**
     * @return array<string, array{template: string, title: string, description: string, priority: string, changefreq: string}>
     */
    public function all(): array
    {
        return self::PAGES;
    }
}
