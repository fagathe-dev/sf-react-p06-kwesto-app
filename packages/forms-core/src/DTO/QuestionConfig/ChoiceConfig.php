<?php

declare(strict_types=1);

namespace FormsCore\DTO\QuestionConfig;

use FormsCore\DTO\Coerce;
use FormsCore\Enum\ChoiceWidget;

/**
 * Configuration d'une question CHOICE (specs_fonc §4.3).
 */
final readonly class ChoiceConfig implements QuestionConfig
{
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 100;
    public const OPTION_LABEL_MAX_LENGTH = 200;

    /**
     * @param list<array{id: string, label: string}> $options liste ordonnée
     */
    public function __construct(
        public ChoiceWidget $widget = ChoiceWidget::Radio,
        public array $options = [],
    ) {
    }

    public static function fromArray(array $data): static
    {
        $widget = ChoiceWidget::tryFrom((string) ($data['widget'] ?? '')) ?? ChoiceWidget::Radio;

        $options = [];
        $seenIds = [];

        foreach (\is_array($data['options'] ?? null) ? $data['options'] : [] as $option) {
            if (!\is_array($option)) {
                continue;
            }

            $id = Coerce::stringOrNull($option['id'] ?? null, 64);
            // Identifiant stable généré par le builder ; à défaut (ou doublon), on en crée un
            if (null === $id || isset($seenIds[$id]) || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                $id = bin2hex(random_bytes(6));
            }

            $seenIds[$id] = true;
            $options[] = [
                'id' => $id,
                'label' => Coerce::stringOrNull($option['label'] ?? null, self::OPTION_LABEL_MAX_LENGTH) ?? '',
            ];

            if (\count($options) >= self::MAX_OPTIONS) {
                break;
            }
        }

        return new self($widget, $options);
    }

    public function toArray(): array
    {
        return [
            'widget' => $this->widget->value,
            'multiple' => $this->isMultiple(),
            'options' => $this->options,
        ];
    }

    public function violations(): array
    {
        $errors = [];

        if (\count($this->options) < self::MIN_OPTIONS) {
            $errors[] = \sprintf('Une question à choix doit proposer au moins %d options.', self::MIN_OPTIONS);
        }

        $labels = [];
        foreach ($this->options as $index => $option) {
            if ('' === $option['label']) {
                $errors[] = \sprintf('L\'option %d n\'a pas de libellé.', $index + 1);
                continue;
            }

            $key = mb_strtolower($option['label']);
            if (isset($labels[$key])) {
                $errors[] = \sprintf('L\'option « %s » est présente plusieurs fois.', $option['label']);
            }
            $labels[$key] = true;
        }

        return $errors;
    }

    public function isMultiple(): bool
    {
        return $this->widget->isMultiple();
    }

    /**
     * @return list<string>
     */
    public function optionIds(): array
    {
        return array_column($this->options, 'id');
    }

    public function labelOf(string $optionId): ?string
    {
        foreach ($this->options as $option) {
            if ($option['id'] === $optionId) {
                return $option['label'];
            }
        }

        return null;
    }
}
