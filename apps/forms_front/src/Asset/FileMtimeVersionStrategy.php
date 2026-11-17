<?php

declare(strict_types=1);

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Invalidation du cache navigateur des fichiers compilés (public/css, public/js) : ?v=<date de modification>.
 * Remplace le versionnement d'AssetMapper, interdit dans forms_front (specs_tech §5.1.1).
 */
final class FileMtimeVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    public function getVersion(string $path): string
    {
        $file = $this->publicDir.'/'.ltrim(parse_url($path, \PHP_URL_PATH) ?: $path, '/');

        return is_file($file) ? (string) filemtime($file) : '';
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        return '' === $version ? $path : $path.(str_contains($path, '?') ? '&' : '?').'v='.$version;
    }
}
