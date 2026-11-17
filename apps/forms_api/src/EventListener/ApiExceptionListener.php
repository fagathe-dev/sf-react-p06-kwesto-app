<?php

declare(strict_types=1);

namespace App\EventListener;

use Fagathe\CorePhp\Enum\LoggerLevelEnum;
use FormsCore\Enum\LogChannel;
use FormsCore\Trait\ChannelLoggerTrait;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Réponses d'erreur JSON homogènes { message } pour toute la route /api, sans fuite de détail technique.
 * Les erreurs 5xx sont journalisées dans le canal « app », les refus d'accès dans « security ».
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -10)]
final class ApiExceptionListener
{
    use ChannelLoggerTrait;

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;

        // Corps JSON invalide (Request::toArray)
        if ($exception instanceof \Symfony\Component\HttpFoundation\Exception\JsonException) {
            $status = Response::HTTP_BAD_REQUEST;
        }

        $message = match (true) {
            Response::HTTP_BAD_REQUEST === $status && !$exception instanceof HttpExceptionInterface => 'Le corps de la requête doit être un JSON valide.',
            $status >= 500 => 'Une erreur interne est survenue.',
            default => '' !== $exception->getMessage() ? $exception->getMessage() : (Response::$statusTexts[$status] ?? 'Erreur'),
        };

        if ($status >= 500) {
            $this->logTo(LogChannel::App, LoggerLevelEnum::Critical, 'Erreur non gérée.', 'api.exception', ['path' => $event->getRequest()->getPathInfo()], $exception);
        } elseif (Response::HTTP_FORBIDDEN === $status) {
            $this->logTo(LogChannel::Security, LoggerLevelEnum::Warning, 'Accès refusé.', 'api.access_denied', ['path' => $event->getRequest()->getPathInfo()]);
        }

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
        $event->setResponse(new JsonResponse(['message' => $message], $status, $headers));
    }
}
