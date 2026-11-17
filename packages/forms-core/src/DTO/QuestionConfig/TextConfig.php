<?php

declare(strict_types=1);

namespace FormsCore\DTO\QuestionConfig;

use FormsCore\DTO\Coerce;

/**
 * Configuration d'une question TEXT (specs_fonc §4.1).
 */
final readonly class TextConfig implements QuestionConfig
{
    public const ABSOLUTE_MAX_LENGTH = 5000;

    public function __construct(
        public bool $multiline = false,
        public ?int $minLength = null,
        public ?int $maxLength = null,
        public ?string $placeholder = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            multiline: Coerce::bool($data['multiline'] ?? null),
            minLength: Coerce::intOrNull($data['minLength'] ?? null),
            maxLength: Coerce::intOrNull($data['maxLength'] ?? null),
            placeholder: Coerce::stringOrNull($data['placeholder'] ?? null, 150),
        );
    }

    public function toArray(): array
    {
        return Coerce::withoutNulls([
            'multiline' => $this->multiline,
            'minLength' => $this->minLength,
            'maxLength' => $this->maxLength,
            'placeholder' => $this->placeholder,
        ]);
    }

    public function violations(): array
    {
        $errors = [];

        if (null !== $this->minLength && $this->minLength < 0) {
            $errors[] = 'La longueur minimale doit être positive ou nulle.';
        }

        if (null !== $this->maxLength && ($this->maxLength < 1 || $this->maxLength > self::ABSOLUTE_MAX_LENGTH)) {
            $errors[] = \sprintf('La longueur maximale doit être comprise entre 1 et %d.', self::ABSOLUTE_MAX_LENGTH);
        }

        if (null !== $this->minLength && null !== $this->maxLength && $this->minLength > $this->maxLength) {
            $errors[] = 'La longueur minimale ne peut pas dépasser la longueur maximale.';
        }

        return $errors;
    }

    public function effectiveMaxLength(): int
    {
        return $this->maxLength ?? self::ABSOLUTE_MAX_LENGTH;
    }
}
