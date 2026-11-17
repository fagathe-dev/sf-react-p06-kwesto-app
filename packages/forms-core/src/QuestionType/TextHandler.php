<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\DTO\AnswerPayload\AnswerPayload;
use FormsCore\DTO\AnswerPayload\TextAnswer;
use FormsCore\DTO\QuestionConfig\TextConfig;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\QuestionType;
use FormsCore\Exception\InvalidAnswerException;
use FormsCore\Util\Statistics;

/**
 * TEXT — zone de texte courte ou longue (specs_fonc §4.1).
 */
final class TextHandler implements QuestionTypeHandlerInterface
{
    public static function getTypeValue(): string
    {
        return QuestionType::Text->value;
    }

    public function normalizeConfig(array $raw): array
    {
        return TextConfig::fromArray($raw)->toArray();
    }

    public function validateConfig(array $config): array
    {
        return TextConfig::fromArray($config)->violations();
    }

    public function isEmptyAnswer(mixed $raw): bool
    {
        return !\is_scalar($raw) || '' === trim((string) $raw);
    }

    public function validateAnswer(Question $question, mixed $raw, SurveyRecipient $recipient): AnswerPayload
    {
        if (!\is_string($raw) && !\is_int($raw) && !\is_float($raw)) {
            throw new InvalidAnswerException('La réponse doit être un texte.');
        }

        $config = TextConfig::fromArray($question->getConfig());
        // trim début / fin ; une réponse courte ne contient pas de retour à la ligne
        $value = trim((string) $raw);
        if (!$config->multiline) {
            $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        }

        $length = mb_strlen($value);

        if (null !== $config->minLength && $length < $config->minLength) {
            throw new InvalidAnswerException(\sprintf('La réponse doit contenir au moins %d caractères.', $config->minLength));
        }

        if ($length > $config->effectiveMaxLength()) {
            throw new InvalidAnswerException(\sprintf('La réponse ne peut pas dépasser %d caractères.', $config->effectiveMaxLength()));
        }

        return new TextAnswer($value);
    }

    public function extractSearchText(array $payload): ?string
    {
        $value = $payload['value'] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    public function computeStats(Question $question, array $payloads): array
    {
        $lengths = [];
        foreach ($payloads as $payload) {
            $lengths[] = mb_strlen((string) ($payload['value'] ?? ''));
        }

        // Les verbatims ne sont pas embarqués : liste paginée avec recherche (StatsService::verbatims)
        return [
            'count' => \count($lengths),
            'averageLength' => Statistics::round(Statistics::mean($lengths), 1),
        ];
    }

    public function exportValue(Question $question, array $payload): string
    {
        return (string) ($payload['value'] ?? '');
    }
}
