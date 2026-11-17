<?php

declare(strict_types=1);

namespace App\Twig;

use FormsCore\Service\PlatformConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Variables globales du site : URL publique (canonical, OpenGraph), URL du back-office (CTA),
 * limite d'upload de la plateforme (lue depuis forms-core, jamais codée en dur).
 */
final class SiteExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly string $siteUrl,
        #[Autowire(param: 'forms_core.url.bo')]
        private readonly ?string $boUrl,
        private readonly PlatformConfig $platformConfig,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'site' => [
                'name' => 'kwesto',
                'url' => rtrim($this->siteUrl, '/'),
                'upload_max_mb' => $this->platformConfig->uploadMaxSizeMb(),
            ],
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_url', $this->appUrl(...)),
            new TwigFunction('absolute_asset', fn (string $path): string => rtrim($this->siteUrl, '/').'/'.ltrim($path, '/')),
        ];
    }

    /**
     * Lien vers le back-office (inscription, connexion) ; « # » si APP_BO_URL n'est pas configurée.
     */
    public function appUrl(string $path = '/login'): string
    {
        $base = rtrim((string) $this->boUrl, '/');

        return '' === $base ? '#' : $base.'/'.ltrim($path, '/');
    }
}
