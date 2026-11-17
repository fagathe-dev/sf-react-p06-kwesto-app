<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\DTO\AnswerPayload\AnswerPayload;
use FormsCore\DTO\AnswerPayload\DateAnswer;
use FormsCore\DTO\Coerce;
use FormsCore\DTO\QuestionConfig\DateConfig;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\QuestionType;
use FormsCore\Exception\InvalidAnswerException;

/**
 * DATE — sélecteur de date (specs_fonc §4.4).
 */
final class DateHandler implements QuestionTypeHandlerInterface
{
    /** Au-delà de cette étendue (en jours), la distribution est regroupée par mois. */
    private const DAILY_DISTRIBUTION_MAX_SPAN = 62;

    public static function getTypeValue(): string
    {
        return QuestionType::Date->value;
    }

    public function normalizeConfig(array $raw): array
    {
        return DateConfig::fromArray($raw)->toArray();
    }

    public function validateConfig(array $config): array
    {
        return DateConfig::fromArray($config)->violations();
    }

    public function isEmptyAnswer(mixed $raw): bool
    {
        return !\is_string($raw) || '' === trim($raw);
    }

    public function validateAnswer(Question $question, mixed $raw, SurveyRecipient $recipient): AnswerPayload
    {
        $date = Coerce::dateOrNull($raw);
        if (null === $date) {
            throw new InvalidAnswerException('La date est invalide (format attendu : AAAA-MM-JJ).');
        }

        $config = DateConfig::fromArray($question->getConfig());

        if (null !== $config->minDate && $date < $config->minDate) {
            throw new InvalidAnswerException(\sprintf('La date doit être égale ou postérieure au %s.', self::toFrench($config->minDate)));
        }

        if (null !== $config->maxDate && $date > $config->maxDate) {
            throw new InvalidAnswerException(\sprintf('La date doit être égale ou antérieure au %s.', self::toFrench($config->maxDate)));
        }

        return new DateAnswer($date);
    }

    public function extractSearchText(array $payload): ?string
    {
        return null;
    }

    public function computeStats(Question $question, array $payloads): array
    {
        $dates = [];
        foreach ($payloads as $payload) {
            $date = Coerce::dateOrNull($payload['value'] ?? null);
            if (null !== $date) {
                $dates[] = $date;
            }
        }

        if ([] === $dates) {
            return ['count' => 0, 'earliest' => null, 'latest' => null, 'median' => null, 'granularity' => 'day', 'distribution' => []];
        }

        sort($dates);
        $count = \count($dates);
        $earliest = $dates[0];
        $latest = $dates[$count - 1];

        $spanDays = (int) (new \DateTimeImmutable($earliest))->diff(new \DateTimeImmutable($latest))->days;
        $granularity = $spanDays > self::DAILY_DISTRIBUTION_MAX_SPAN ? 'month' : 'day';

        $distribution = [];
        foreach ($dates as $date) {
            $period = 'month' === $granularity ? substr($date, 0, 7) : $date;
            $distribution[$period] = ($distribution[$period] ?? 0) + 1;
        }

        $buckets = [];
        foreach ($distribution as $period => $total) {
            $buckets[] = ['period' => (string) $period, 'count' => $total];
        }

        return [
            'count' => $count,
            'earliest' => $earliest,
            'latest' => $latest,
            'median' => self::median($dates),
            'granularity' => $granularity,
            'distribution' => $buckets,
        ];
    }

    public function exportValue(Question $question, array $payload): string
    {
        $date = Coerce::dateOrNull($payload['value'] ?? null);

        return null === $date ? '' : self::toFrench($date);
    }

    /**
     * @param non-empty-list<string> $sortedDates dates YYYY-MM-DD triées
     */
    private static function median(array $sortedDates): string
    {
        $count = \count($sortedDates);
        $middle = intdiv($count, 2);

        if (1 === $count % 2) {
            return $sortedDates[$middle];
        }

        // Nombre pair : date à mi-chemin entre les deux valeurs centrales
        $lower = new \DateTimeImmutable($sortedDates[$middle - 1]);
        $gapDays = (int) $lower->diff(new \DateTimeImmutable($sortedDates[$middle]))->days;

        return $lower->modify(\sprintf('+%d days', intdiv($gapDays, 2)))->format('Y-m-d');
    }

    private static function toFrench(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('d/m/Y');
    }
}
