<?php

declare(strict_types=1);

namespace FormsCore\DTO;

use FormsCore\Enum\ConditionOperator;

/**
 * Condition d'affichage d'une question (specs_tech §9.3) : la question n'est affichée que si la réponse
 * à sa question source (de position inférieure) satisfait l'opérateur.
 */
final readonly class DisplayCondition
{
    /**
     * @param string|int|float|bool|list<string> $value selon le type de la source (specs_fonc §3.5)
     */
    public function __construct(
        public string $sourceQuestionId,
        public ConditionOperator $operator,
        public string|int|float|bool|array $value,
    ) {
    }

    /**
     * Null si la forme reçue est inexploitable (pas de source, opérateur inconnu, valeur absente).
     */
    public static function fromArray(mixed $raw): ?self
    {
        if (!\is_array($raw)) {
            return null;
        }

        $source = \is_string($raw['sourceQuestionId'] ?? null) ? trim($raw['sourceQuestionId']) : '';
        $operator = ConditionOperator::tryFrom((string) ($raw['operator'] ?? ''));
        $value = $raw['value'] ?? null;

        if ('' === $source || null === $operator || null === $value) {
            return null;
        }

        if (\is_array($value)) {
            $value = array_values(array_filter($value, static fn (mixed $v): bool => \is_string($v) && '' !== trim($v)));
        } elseif (!\is_scalar($value)) {
            return null;
        }

        return new self($source, $operator, $value);
    }

    public function withSource(string $sourceQuestionId): self
    {
        return new self($sourceQuestionId, $this->operator, $this->value);
    }

    /**
     * @return array{sourceQuestionId: string, operator: string, value: string|int|float|bool|list<string>}
     */
    public function toArray(): array
    {
        return ['sourceQuestionId' => $this->sourceQuestionId, 'operator' => $this->operator->value, 'value' => $this->value];
    }
}
