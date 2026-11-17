<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * L'API n'est jamais indexable (specs_tech §6) : X-Robots-Tag sur toutes les réponses.
 */
#[AsEventListener]
final class NoIndexListener
{
    public function __invoke(ResponseEvent $event): void
    {
        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
}
