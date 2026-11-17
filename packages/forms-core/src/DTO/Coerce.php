<?php

declare(strict_types=1);

namespace FormsCore\DTO;

/**
 * Conversions tolérantes des valeurs JSON reçues du builder vers les types des contrats.
 * Une valeur inexploitable devient null (ou la valeur par défaut) : la validation métier signale ensuite l'erreur.
 */
final class Coerce
{
    public static function bool(mixed $value, bool $default = false): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return null === $value ? $default : filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function intOrNull(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    public static function floatOrNull(mixed $value): ?float
    {
        if (\is_int($value) || \is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }

        if (\is_string($value)) {
            $normalized = str_replace(',', '.', trim($value));

            return is_numeric($normalized) && is_finite((float) $normalized) ? (float) $normalized : null;
        }

        return null;
    }

    public static function stringOrNull(mixed $value, int $maxLength = 255): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return '' === $string ? null : mb_substr($string, 0, $maxLength);
    }

    /**
     * Date calendaire valide au format YYYY-MM-DD, sinon null.
     */
    public static function dateOrNull(mixed $value): ?string
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? trim($value) : null;
    }

    /**
     * Supprime les clés nulles : un paramètre optionnel absent n'est pas sérialisé.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function withoutNulls(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => null !== $value);
    }
}
