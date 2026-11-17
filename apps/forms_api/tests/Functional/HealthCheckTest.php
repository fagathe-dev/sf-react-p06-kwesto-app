<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class HealthCheckTest extends ApiTestCase
{
    public function testHealthCheckIsPublicAndReportsEveryCheck(): void
    {
        $body = $this->api('GET', '/api/health-check');

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $body['status']);
        self::assertSame('kwesto-api', $body['service']);
        self::assertSame('healthy', $body['checks']['database']['status']);
        self::assertIsFloat($body['checks']['database']['latency_ms'] + 0.0);
        self::assertTrue($body['checks']['storage']['var_writable']);
        self::assertTrue($body['checks']['storage']['uploads_writable']);
        self::assertTrue($body['checks']['storage']['logs_writable']);
        self::assertArrayHasKey('max_size_mb', $body['checks']['uploads']);
        self::assertArrayHasKey('php_limit_consistent', $body['checks']['uploads']);

        // Sonde : jamais de cache, jamais d'indexation
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function testFormerHealthRouteNoLongerExists(): void
    {
        $this->api('GET', '/api/health');

        // Route inconnue sous /api : refusée à un visiteur anonyme, avant même le routage
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 404]);
    }
}
