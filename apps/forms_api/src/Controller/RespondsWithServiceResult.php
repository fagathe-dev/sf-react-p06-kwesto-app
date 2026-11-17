<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Les services de forms-core renvoient l'objet de ResponseTrait (core-php) :
 * { data, status, headers, context }. Le contrôleur le transforme en réponse JSON.
 */
trait RespondsWithServiceResult
{
    private function respond(object $result): JsonResponse
    {
        if (\Symfony\Component\HttpFoundation\Response::HTTP_NO_CONTENT === $result->status) {
            return new JsonResponse(null, $result->status, $result->headers);
        }

        return $this->json($result->data, $result->status, $result->headers, $result->context);
    }
}
