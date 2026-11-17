<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\DTO\AnswerPayload\AnswerPayload;
use FormsCore\DTO\AnswerPayload\NumberAnswer;
use FormsCore\DTO\QuestionConfig\NumberConfig;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\QuestionType;
use FormsCore\Exception\InvalidAnswerException;
use FormsCore\Util\Statistics;

/**
 * NUMBER — champ numérique acceptant « . » et « , » comme séparateur décimal (specs_fonc §4.2).
 */
final class NumberHandler implements QuestionTypeHandlerInterface
{
    public static function getTypeValue(): string
    {
        return QuestionType::Number->value;
    }

    public function normalizeConfig(array $raw): array
    {
        return NumberConfig::fromArray($raw)->toArray();
    }

    public function validateConfig(array $config): array
    {
        return NumberConfig::fromArray($config)->violations();
    }

    public function isEmptyAnswer(mixed $raw): bool
    {
        return !\is_scalar($raw) || \is_bool($raw) || '' === trim((string) $raw);
    }

    public function validateAnswer(Question $question, mixed $raw, SurveyRecipient $recipient): AnswerPayload
    {
        if (!\is_string($raw) && !\is_int($raw) && !\is_float($raw)) {
            throw new InvalidAnswerException('La réponse doit être un nombre.');
        }

        // Normalisation de la virgule en point avant analyse (les espaces de milliers sont tolérés)
        $normalized = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], trim((string) $raw));

        if (1 !== preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $normalized)) {
            throw new InvalidAnswerException('La réponse doit être un nombre valide.');
        }

        $config = NumberConfig::fromArray($question->getConfig());
        $value = (float) $normalized;

        if (!$config->allowDecimal && floor($value) !== $value) {
            throw new InvalidAnswerException('La réponse doit être un nombre entier.');
        }

        // Intervalle strict : min <= valeur <= max
        if (null !== $config->min && $value < $config->min) {
            throw new InvalidAnswerException(\sprintf('La valeur doit être supérieure ou égale à %s.', self::format($config->min)));
        }

        if (null !== $config->max && $value > $config->max) {
            throw new InvalidAnswerException(\sprintf('La valeur doit être inférieure ou égale à %s.', self::format($config->max)));
        }

        return new NumberAnswer(floor($value) === $value && abs($value) < \PHP_INT_MAX ? (int) $value : $value);
    }

    public function extractSearchText(array $payload): ?string
    {
        return null;
    }

    public function computeStats(Question $question, array $payloads): array
    {
        $values = [];
        foreach ($payloads as $payload) {
            if (is_numeric($payload['value'] ?? null)) {
                $values[] = $payload['value'] + 0;
            }
        }

        $config = NumberConfig::fromArray($question->getConfig());

        return [
            'count' => \count($values),
            'min' => [] === $values ? null : min($values),
            'max' => [] === $values ? null : max($values),
            'mean' => Statistics::round(Statistics::mean($values)),
            'median' => Statistics::round(Statistics::median($values)),
            'standardDeviation' => Statistics::round(Statistics::standardDeviation($values)),
            'histogram' => Statistics::histogram($values, !$config->allowDecimal),
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
