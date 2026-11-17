<?php

declare(strict_types=1);

namespace FormsCore\DTO\QuestionConfig;

use FormsCore\DTO\Coerce;

/**
 * Configuration d'une question RANGE — curseur sur une échelle (specs_fonc §4.7).
 *
 * Deux façons de définir l'échelle :
 * - intervalle régulier : min / max / step (défaut 0 à 10, pas de 1) ;
 * - valeurs personnalisées : `values`, liste libre triée (ex. 2, 4, 6, 22, 40, 79) ; le curseur avance
 *   d'une valeur à la suivante, min et max en sont déduits et le pas est ignoré.
 * Commun : minLabel / maxLabel (libellés des extrémités), unit (suffixe affiché : « /10 », « % »),
 * defaultValue (position de départ comptée comme réponse, ou null : le participant doit déplacer le curseur).
 */
final readonly class RangeConfig implements QuestionConfig
{
    public const LABEL_MAX_LENGTH = 60;
    public const UNIT_MAX_LENGTH = 12;
    /** Nombre maximal de crans : au-delà, le curseur n'est plus utilisable au clavier ni au doigt. */
    public const MAX_STEPS = 1000;

    /**
     * @param list<float>|null $values valeurs personnalisées, triées et sans doublon ; null = intervalle régulier
     */
    public function __construct(
        public float $min = 0,
        public float $max = 10,
        public float $step = 1,
        public ?string $minLabel = null,
        public ?string $maxLabel = null,
        public ?string $unit = null,
        public ?float $defaultValue = null,
        public ?array $values = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        $values = null;
        if (\is_array($data['values'] ?? null)) {
            $values = [];
            foreach ($data['values'] as $value) {
                $number = \is_bool($value) ? null : Coerce::floatOrNull($value);
                if (null !== $number) {
                    $values[(string) round($number, 6)] = round($number, 6);
                }
            }
            sort($values);
            $values = array_slice(array_values($values), 0, self::MAX_STEPS + 1);
        }

        $custom = null !== $values && [] !== $values;

        return new self(
            $custom ? $values[0] : (Coerce::floatOrNull($data['min'] ?? null) ?? 0),
            $custom ? $values[\count($values) - 1] : (Coerce::floatOrNull($data['max'] ?? null) ?? 10),
            Coerce::floatOrNull($data['step'] ?? null) ?? 1,
            Coerce::stringOrNull($data['minLabel'] ?? null, self::LABEL_MAX_LENGTH),
            Coerce::stringOrNull($data['maxLabel'] ?? null, self::LABEL_MAX_LENGTH),
            // Le suffixe garde son espace initial éventuel (« 8 % ») : seul l'excédent est coupé
            \is_string($data['unit'] ?? null) && '' !== trim($data['unit']) ? mb_substr(rtrim($data['unit']), 0, self::UNIT_MAX_LENGTH) : null,
            Coerce::floatOrNull($data['defaultValue'] ?? null),
            // Une liste vide reste une liste (brouillon en cours de saisie, signalé à la validation)
            $values,
        );
    }

    public function isCustom(): bool
    {
        return null !== $this->values;
    }

    public function toArray(): array
    {
        $data = [
            'min' => self::number($this->min),
            'max' => self::number($this->max),
            'step' => self::number($this->step),
            'minLabel' => $this->minLabel,
            'maxLabel' => $this->maxLabel,
            'unit' => $this->unit,
        ];

        if ($this->isCustom()) {
            $data['values'] = array_map(self::number(...), $this->values ?? []);
        }

        return Coerce::withoutNulls($data) + ['defaultValue' => null === $this->defaultValue ? null : self::number($this->defaultValue)];
    }

    public function violations(): array
    {
        $errors = [];

        if ($this->isCustom()) {
            if (\count($this->values ?? []) < 2) {
                $errors[] = 'Une échelle personnalisée doit compter au moins 2 valeurs.';
            }
        } elseif ($this->min >= $this->max) {
            $errors[] = 'La valeur minimale doit être inférieure à la valeur maximale.';
        } elseif ($this->step <= 0) {
            $errors[] = 'Le pas doit être supérieur à 0.';
        } elseif (($this->max - $this->min) / $this->step > self::MAX_STEPS) {
            $errors[] = \sprintf('L\'échelle compte trop de crans (%d au maximum) : augmentez le pas.', self::MAX_STEPS);
        } elseif (!$this->isOnStep($this->max)) {
            $errors[] = 'L\'écart entre le minimum et le maximum doit être un multiple du pas.';
        }

        if (null !== $this->defaultValue && !$this->accepts($this->defaultValue)) {
            $errors[] = 'La valeur de départ doit être une valeur de l\'échelle.';
        }

        return $errors;
    }

    /** La valeur fait-elle partie de l'échelle ? */
    public function accepts(float $value): bool
    {
        if ($this->isCustom()) {
            foreach ($this->values ?? [] as $allowed) {
                if (abs($allowed - $value) < 1e-9) {
                    return true;
                }
            }

            return false;
        }

        return $value >= $this->min && $value <= $this->max && $this->isOnStep($value);
    }

    /**
     * Toutes les valeurs de l'échelle (répartition des réponses).
     *
     * @return list<int|float>
     */
    public function values(): array
    {
        if ($this->isCustom()) {
            return array_map(self::number(...), $this->values ?? []);
        }

        if ($this->step <= 0 || $this->min >= $this->max) {
            return [];
        }

        $values = [];
        $steps = (int) round(($this->max - $this->min) / $this->step);
        for ($i = 0; $i <= $steps && $i <= self::MAX_STEPS; ++$i) {
            $values[] = self::number($this->min + $i * $this->step);
        }

        return $values;
    }

    private function isOnStep(float $value): bool
    {
        if ($this->step <= 0) {
            return false;
        }
        $ratio = ($value - $this->min) / $this->step;

        return abs($ratio - round($ratio)) < 1e-9;
    }

    /** Entier si la valeur est entière (« 8 » et non « 8.0 » dans le JSON). */
    private static function number(float $value): int|float
    {
        $value = round($value, 6);

        return floor($value) === $value && abs($value) < \PHP_INT_MAX ? (int) $value : $value;
    }
}
