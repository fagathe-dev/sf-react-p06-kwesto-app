<?php

declare(strict_types=1);

namespace FormsCore\DTO\QuestionConfig;

use FormsCore\DTO\Coerce;

/**
 * Configuration d'une question DATE (specs_fonc §4.4). Dates au format YYYY-MM-DD.
 */
final readonly class DateConfig implements QuestionConfig
{
    public function __construct(
        public ?string $minDate = null,
        public ?string $maxDate = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        return new self(
            minDate: Coerce::dateOrNull($data['minDate'] ?? null),
            maxDate: Coerce::dateOrNull($data['maxDate'] ?? null),
        );
    }

    public function toArray(): array
    {
        return Coerce::withoutNulls([
            'minDate' => $this->minDate,
            'maxDate' => $this->maxDate,
        ]);
    }

    public function violations(): array
    {
        // Le format YYYY-MM-DD rend la comparaison de chaînes équivalente à la comparaison de dates
        if (null !== $this->minDate && null !== $this->maxDate && $this->minDate > $this->maxDate) {
            return ['La date minimale ne peut pas être postérieure à la date maximale.'];
        }

        return [];
    }
}
