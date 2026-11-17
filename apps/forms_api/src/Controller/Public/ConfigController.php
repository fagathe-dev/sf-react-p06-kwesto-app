<?php

declare(strict_types=1);

namespace App\Controller\Public;

use FormsCore\Service\PlatformConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration de la plateforme, lue par le builder et par la vue répondant (specs_tech §8.5) :
 * { uploadMaxSizeMb, fileFormats }.
 */
final class ConfigController extends AbstractController
{
    #[Route('/public/config', name: 'api_public_config', methods: ['GET'])]
    public function __invoke(PlatformConfig $config): JsonResponse
    {
        $response = $this->json($config->toArray());
        $response->setPublic()->setMaxAge(300);

        return $response;
    }
}
