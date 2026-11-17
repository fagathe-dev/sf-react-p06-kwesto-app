<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\Enum\QuestionType;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Retrouve le handler d'un type de question. Ajouter un type = un case d'enum + un handler.
 */
final class QuestionTypeRegistry
{
    public function __construct(
        #[AutowireLocator(QuestionTypeHandlerInterface::TAG, defaultIndexMethod: 'getTypeValue')]
        private readonly ContainerInterface $handlers,
    ) {
    }

    public function get(QuestionType $type): QuestionTypeHandlerInterface
    {
        if (!$this->handlers->has($type->value)) {
            throw new \LogicException(\sprintf('Aucun handler (%s) pour le type de question "%s".', QuestionTypeHandlerInterface::class, $type->value));
        }

        /** @var QuestionTypeHandlerInterface $handler */
        $handler = $this->handlers->get($type->value);

        return $handler;
    }
}
