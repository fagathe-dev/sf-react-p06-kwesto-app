<?php

declare(strict_types=1);

namespace FormsCore\DTO\QuestionConfig;

use FormsCore\DTO\Coerce;

/**
 * Configuration d'une question NUMBER (specs_fonc §4.2).
 */
final readonly class NumberConfig implements QuestionConfig
{
    public function __construct(
        public bool $allowDecimal = false,
        public ?float $min = null,
        public ?float $max = null,
        public ?float $step = null,
        public ?string $unit = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            allowDecimal: Coerce::bool($data['allowDecimal'] ?? null),
            min: Coerce::floatOrNull($data['min'] ?? null),
            max: Coerce::floatOrNull($data['max'] ?? null),
            step: Coerce::floatOrNull($data['step'] ?? null),
            unit: Coerce::stringOrNull($data['unit'] ?? null, 12),
        );
    }

    public function toArray(): array
    {
        return Coerce::withoutNulls([
            'allowDecimal' => $this->allowDecimal,
            'min' => self::compact($this->min),
            'max' => self::compact($this->max),
            'step' => self::compact($this->step),
            'unit' => $this->unit,
        ]);
    }

    public function violations(): array
    {
        $errors = [];

        if (null !== $this->min && null !== $this->max && $this->min > $this->max) {
            $errors[] = 'La valeur minimale ne peut pas dépasser la valeur maximale.';
        }

        if (null !== $this->step && $this->step <= 0) {
            $errors[] = 'Le pas d\'incrémentation doit être strictement positif.';
        }

        if (!$this->allowDecimal) {
            foreach (['min' => $this->min, 'max' => $this->max, 'step' => $this->step] as $name => $value) {
                if (null !== $value && floor($value) !== $value) {
                    $errors[] = \sprintf('« %s » doit être un entier quand les décimales ne sont pas autorisées.', $name);
                }
            }
        }

        return $errors;
    }

    /**
     * 10.0 → 10 (entier JSON), 2.5 → 2.5.
     */
    private static function compact(?float $value): int|float|null
    {
        if (null === $value) {
            return null;
        }

        return floor($value) === $value && abs($value) < \PHP_INT_MAX ? (int) $value : $value;
    }
}
