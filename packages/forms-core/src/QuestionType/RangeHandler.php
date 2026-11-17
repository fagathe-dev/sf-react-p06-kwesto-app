<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\DTO\AnswerPayload\AnswerPayload;
use FormsCore\DTO\AnswerPayload\NumberAnswer;
use FormsCore\DTO\Coerce;
use FormsCore\DTO\QuestionConfig\RangeConfig;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\QuestionType;
use FormsCore\Exception\InvalidAnswerException;
use FormsCore\Util\Statistics;

/**
 * RANGE — curseur sur une échelle bornée, avec libellés d'extrémités (specs_fonc §4.7).
 */
final class RangeHandler implements QuestionTypeHandlerInterface
{
    public static function getTypeValue(): string
    {
        return QuestionType::Range->value;
    }

    public function normalizeConfig(array $raw): array
    {
        return RangeConfig::fromArray($raw)->toArray();
    }

    public function validateConfig(array $config): array
    {
        return RangeConfig::fromArray($config)->violations();
    }

    public function isEmptyAnswer(mixed $raw): bool
    {
        return !\is_scalar($raw) || \is_bool($raw) || '' === trim((string) $raw);
    }

    public function validateAnswer(Question $question, mixed $raw, SurveyRecipient $recipient): AnswerPayload
    {
        $value = \is_bool($raw) ? null : Coerce::floatOrNull($raw);
        if (null === $value) {
            throw new InvalidAnswerException('La réponse doit être une valeur de l\'échelle.');
        }

        $config = RangeConfig::fromArray($question->getConfig());
        if (!$config->accepts($value)) {
            throw new InvalidAnswerException($config->isCustom()
                ? 'La réponse doit être l\'une des valeurs de l\'échelle.'
                : \sprintf('La réponse doit être comprise entre %s et %s.', self::format($config->min), self::format($config->max)));
        }

        return new NumberAnswer(floor($value) === $value && abs($value) < \PHP_INT_MAX ? (int) $value : round($value, 6));
    }

    public function extractSearchText(array $payload): ?string
    {
        return null;
    }

    /**
     * Moyenne, médiane, minimum, maximum et répartition par cran (ou histogramme au-delà de 21 crans).
     */
    public function computeStats(Question $question, array $payloads): array
    {
        $config = RangeConfig::fromArray($question->getConfig());
        $values = [];
        foreach ($payloads as $payload) {
            if (is_numeric($payload['value'] ?? null)) {
                $values[] = $payload['value'] + 0;
            }
        }

        $scale = $config->values();
        $distribution = [];
        if (\count($scale) <= 21) {
            $counts = array_fill_keys(array_map('strval', $scale), 0);
            foreach ($values as $value) {
                $key = (string) $value;
                if (isset($counts[$key])) {
                    ++$counts[$key];
                }
            }
            foreach ($scale as $step) {
                $distribution[] = ['value' => $step, 'count' => $counts[(string) $step]];
            }
        }

        return [
            'count' => \count($values),
            'min' => [] === $values ? null : min($values),
            'max' => [] === $values ? null : max($values),
            'mean' => Statistics::round(Statistics::mean($values)),
            'median' => Statistics::round(Statistics::median($values)),
            'scaleMin' => $scale[0] ?? null,
            'scaleMax' => $scale[\count($scale) - 1] ?? null,
            'minLabel' => $config->minLabel,
            'maxLabel' => $config->maxLabel,
            'unit' => $config->unit,
            // Répartition cran par cran si l'échelle est courte, histogramme par tranches sinon
            'distribution' => $distribution,
            'histogram' => [] === $distribution ? Statistics::histogram($values, !$config->isCustom() && floor($config->step) === $config->step) : [],
        ];
    }

    public function exportValue(Question $question, array $payload): string
    {
        return is_numeric($payload['value'] ?? null) ? self::format($payload['value'] + 0) : '';
    }

    private static function format(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 6, ',', ''), '0'), ',');
    }
}
