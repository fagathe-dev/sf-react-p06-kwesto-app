<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\DTO\AnswerPayload\AnswerPayload;
use FormsCore\DTO\AnswerPayload\ChoiceAnswer;
use FormsCore\DTO\QuestionConfig\ChoiceConfig;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\QuestionType;
use FormsCore\Exception\InvalidAnswerException;
use FormsCore\Util\Statistics;

/**
 * CHOICE — boutons radio, cases à cocher ou liste déroulante (specs_fonc §4.3).
 */
final class ChoiceHandler implements QuestionTypeHandlerInterface
{
    public static function getTypeValue(): string
    {
        return QuestionType::Choice->value;
    }

    public function normalizeConfig(array $raw): array
    {
        return ChoiceConfig::fromArray($raw)->toArray();
    }

    public function validateConfig(array $config): array
    {
        return ChoiceConfig::fromArray($config)->violations();
    }

    public function isEmptyAnswer(mixed $raw): bool
    {
        return [] === self::toIds($raw);
    }

    public function validateAnswer(Question $question, mixed $raw, SurveyRecipient $recipient): AnswerPayload
    {
        if (!\is_array($raw) && !\is_string($raw)) {
            throw new InvalidAnswerException('La sélection est invalide.');
        }

        $config = ChoiceConfig::fromArray($question->getConfig());
        $selected = self::toIds($raw);
        $allowed = $config->optionIds();

        // Chaque identifiant sélectionné doit appartenir aux options de la question
        if ([] !== array_diff($selected, $allowed)) {
            throw new InvalidAnswerException('Une option sélectionnée n\'existe pas dans cette question.');
        }

        if (!$config->isMultiple() && \count($selected) > 1) {
            throw new InvalidAnswerException('Une seule option peut être sélectionnée.');
        }

        // Ordre des options de la question, pas celui du clic
        return new ChoiceAnswer(array_values(array_intersect($allowed, $selected)));
    }

    public function extractSearchText(array $payload): ?string
    {
        return null;
    }

    public function computeStats(Question $question, array $payloads): array
    {
        $config = ChoiceConfig::fromArray($question->getConfig());
        $counts = array_fill_keys($config->optionIds(), 0);

        foreach ($payloads as $payload) {
            foreach (self::toIds($payload['optionIds'] ?? []) as $optionId) {
                if (isset($counts[$optionId])) {
                    ++$counts[$optionId];
                }
            }
        }

        // Base des pourcentages : les répondants à la question (un choix multiple peut dépasser 100 % au total)
        $respondents = \count($payloads);
        $options = [];
        foreach ($config->options as $option) {
            $options[] = [
                'id' => $option['id'],
                'label' => $option['label'],
                'count' => $counts[$option['id']],
                'percent' => Statistics::percent($counts[$option['id']], $respondents),
            ];
        }

        return [
            'count' => $respondents,
            'multiple' => $config->isMultiple(),
            'options' => $options,
        ];
    }

    public function exportValue(Question $question, array $payload): string
    {
        $config = ChoiceConfig::fromArray($question->getConfig());
        $labels = [];

        foreach (self::toIds($payload['optionIds'] ?? []) as $optionId) {
            $labels[] = $config->labelOf($optionId) ?? $optionId;
        }

        return implode(' | ', $labels);
    }

    /**
     * @return list<string> identifiants distincts, quelle que soit la forme reçue (chaîne ou liste)
     */
    private static function toIds(mixed $raw): array
    {
        $values = \is_array($raw) ? $raw : [$raw];
        $ids = [];

        foreach ($values as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $ids[trim($value)] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }
}
