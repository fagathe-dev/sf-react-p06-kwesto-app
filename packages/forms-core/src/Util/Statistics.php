<?php

declare(strict_types=1);

namespace FormsCore\Util;

/**
 * Fonctions statistiques descriptives utilisées par les handlers de types de questions.
 */
final class Statistics
{
    /**
     * @param list<int|float> $values
     */
    public static function mean(array $values): ?float
    {
        return [] === $values ? null : array_sum($values) / \count($values);
    }

    /**
     * @param list<int|float> $values
     */
    public static function median(array $values): ?float
    {
        if ([] === $values) {
            return null;
        }

        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);

        return 0 === $count % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : (float) $values[$middle];
    }

    /**
     * Écart-type de la population (les réponses sont la population complète, pas un échantillon).
     *
     * @param list<int|float> $values
     */
    public static function standardDeviation(array $values): ?float
    {
        $mean = self::mean($values);
        if (null === $mean) {
            return null;
        }

        $sumOfSquares = 0.0;
        foreach ($values as $value) {
            $sumOfSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumOfSquares / \count($values));
    }

    /**
     * Histogramme par tranches de largeur égale. Des valeurs entières sur une petite étendue
     * donnent une tranche par valeur.
     *
     * @param list<int|float> $values
     *
     * @return list<array{from: float, to: float, count: int}>
     */
    public static function histogram(array $values, bool $integersOnly = false, int $maxBuckets = 10): array
    {
        if ([] === $values) {
            return [];
        }

        $min = (float) min($values);
        $max = (float) max($values);

        if ($min === $max) {
            return [['from' => $min, 'to' => $max, 'count' => \count($values)]];
        }

        if ($integersOnly && ($max - $min) < $maxBuckets) {
            $bucketCount = (int) ($max - $min) + 1;
            $width = 1.0;
            $buckets = [];
            for ($i = 0; $i < $bucketCount; ++$i) {
                $buckets[] = ['from' => $min + $i, 'to' => $min + $i, 'count' => 0];
            }
        } else {
            $bucketCount = max(1, min($maxBuckets, (int) ceil(sqrt(\count($values)))));
            $width = ($max - $min) / $bucketCount;
            $buckets = [];
            for ($i = 0; $i < $bucketCount; ++$i) {
                $buckets[] = [
                    'from' => self::round($min + $i * $width),
                    'to' => self::round($i === $bucketCount - 1 ? $max : $min + ($i + 1) * $width),
                    'count' => 0,
                ];
            }
        }

        foreach ($values as $value) {
            $index = (int) floor(($value - $min) / $width);
            ++$buckets[min($index, $bucketCount - 1)]['count'];
        }

        return $buckets;
    }

    /**
     * Pourcentage arrondi à une décimale, 0 si la base est nulle.
     */
    public static function percent(int|float $part, int|float $total): float
    {
        return $total > 0 ? round($part / $total * 100, 1) : 0.0;
    }

    public static function round(?float $value, int $precision = 2): ?float
    {
        return null === $value ? null : round($value, $precision);
    }
}
