<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\DTO\AnswerPayload\AnswerPayload;
use FormsCore\DTO\AnswerPayload\BooleanAnswer;
use FormsCore\DTO\QuestionConfig\BooleanConfig;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\QuestionType;
use FormsCore\Exception\InvalidAnswerException;
use FormsCore\Util\Statistics;

/**
 * BOOLEAN — choix binaire, deux boutons ou interrupteur, valeur par défaut possible (specs_fonc §4.6).
 */
final class BooleanHandler implements QuestionTypeHandlerInterface
{
    public static function getTypeValue(): string
    {
        return QuestionType::Boolean->value;
    }

    public function normalizeConfig(array $raw): array
    {
        return BooleanConfig::fromArray($raw)->toArray();
    }

    public function validateConfig(array $config): array
    {
        return BooleanConfig::fromArray($config)->violations();
    }

    /** `false` est une réponse : seuls null et la chaîne vide valent « pas de réponse ». */
    public function isEmptyAnswer(mixed $raw): bool
    {
        return null === $raw || (\is_string($raw) && '' === trim($raw));
    }

    public function validateAnswer(Question $question, mixed $raw, SurveyRecipient $recipient): AnswerPayload
    {
        $value = self::toBool($raw);
        if (null === $value) {
            throw new InvalidAnswerException('La réponse doit être l\'une des deux valeurs proposées.');
        }

        return new BooleanAnswer($value);
    }

    public function extractSearchText(array $payload): ?string
    {
        return null;
    }

    public function computeStats(Question $question, array $payloads): array
    {
        $config = BooleanConfig::fromArray($question->getConfig());
        $trueCount = 0;
        $falseCount = 0;

        foreach ($payloads as $payload) {
            $value = self::toBool($payload['value'] ?? null);
            if (true === $value) {
                ++$trueCount;
            } elseif (false === $value) {
                ++$falseCount;
            }
        }

        $count = $trueCount + $falseCount;

        return [
            'count' => $count,
            'trueCount' => $trueCount,
            'falseCount' => $falseCount,
            'truePercent' => Statistics::percent($trueCount, $count),
            'falsePercent' => Statistics::percent($falseCount, $count),
            'trueLabel' => $config->trueLabel,
            'falseLabel' => $config->falseLabel,
        ];
    }

    public function exportValue(Question $question, array $payload): string
    {
        $value = self::toBool($payload['value'] ?? null);

        return null === $value ? '' : BooleanConfig::fromArray($question->getConfig())->labelOf($value);
    }

    /** true / false, "true" / "false", 1 / 0 ; null pour toute autre valeur. */
    private static function toBool(mixed $raw): ?bool
    {
        return match (true) {
            \is_bool($raw) => $raw,
            1 === $raw, '1' === $raw, 'true' === $raw => true,
            0 === $raw, '0' === $raw, 'false' === $raw => false,
            default => null,
        };
    }
}
