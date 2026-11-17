<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use FormsCore\Service\AssetStorage;
use FormsCore\Service\PlatformConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * État de santé de l'API, pour la supervision (sonde de disponibilité, load balancer).
 *
 * 200 si tout est opérationnel, 503 si la base est injoignable ou si un dossier d'écriture est inaccessible.
 * Aucune information sensible : ni identifiants, ni chemins absolus, ni versions détaillées des dépendances.
 */
final class HealthCheckController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AssetStorage $assetStorage,
        private readonly PlatformConfig $platformConfig,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%core_php.logger.logs_dir%')]
        private readonly string $logsDir,
    ) {
    }

    #[Route('/health-check', name: 'api_health_check', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $start = microtime(true);
        $database = $this->checkDatabase();

        $storage = [
            'var_writable' => is_writable($this->projectDir.'/var'),
            'uploads_writable' => $this->isWritableDir($this->assetStorage->rootDir()),
            'logs_writable' => $this->isWritableDir($this->logsDir),
        ];

        $healthy = 'healthy' === $database['status'] && !\in_array(false, $storage, true);

        $response = $this->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'kwesto-api',
            'timestamp' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'checks' => [
                'database' => $database,
                'storage' => $storage,
                'uploads' => [
                    'max_size_mb' => $this->platformConfig->uploadMaxSizeMb(),
                    // upload_max_filesize et post_max_size doivent couvrir UPLOAD_MAX_SIZE_MB (specs_tech §8.5)
                    'php_limit_consistent' => $this->platformConfig->isPhpLimitConsistent(),
                ],
            ],
            'system' => [
                'php_version' => \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION,
                'environment' => $this->getParameter('kernel.environment'),
                'response_ms' => round((microtime(true) - $start) * 1000, 2),
            ],
        ], $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);

        // Une sonde doit toujours lire l'état réel, jamais une réponse en cache
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array{status: 'healthy'|'unhealthy', latency_ms: float|null}
     */
    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            $this->connection->executeQuery('SELECT 1');

            return ['status' => 'healthy', 'latency_ms' => round((microtime(true) - $start) * 1000, 2)];
        } catch (\Throwable) {
            return ['status' => 'unhealthy', 'latency_ms' => null];
        }
    }

    /**
     * Dossier existant et inscriptible ; s'il n'existe pas encore, son parent doit l'être (création au premier usage).
     */
    private function isWritableDir(string $dir): bool
    {
        $dir = rtrim($dir, '/\\');

        return is_dir($dir) ? is_writable($dir) : is_writable(\dirname($dir));
    }
}
