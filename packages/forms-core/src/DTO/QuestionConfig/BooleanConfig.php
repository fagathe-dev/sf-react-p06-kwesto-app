<?php

declare(strict_types=1);

namespace FormsCore\DTO\QuestionConfig;

use FormsCore\DTO\Coerce;

/**
 * Configuration d'une question BOOLEAN (specs_fonc §4.6).
 *
 * - preset : modèle de libellés (YES_NO, TRUE_FALSE) ou CUSTOM ;
 * - widget : `buttons` (deux boutons exclusifs) ou `switch` (interrupteur) ;
 * - defaultValue : réponse pré-remplie (true / false) ou null. Un interrupteur n'a pas d'état « sans réponse » :
 *   sa valeur par défaut vaut false si elle n'est pas précisée.
 */
final readonly class BooleanConfig implements QuestionConfig
{
    public const LABEL_MAX_LENGTH = 80;
    public const PRESETS = [
        'YES_NO' => ['Oui', 'Non'],
        'TRUE_FALSE' => ['Vrai', 'Faux'],
    ];
    public const WIDGETS = ['buttons', 'switch'];

    public function __construct(
        public string $preset = 'YES_NO',
        public string $trueLabel = 'Oui',
        public string $falseLabel = 'Non',
        public string $widget = 'buttons',
        public ?bool $defaultValue = null,
    ) {
    }

    public static function fromArray(array $data): static
    {
        $preset = \is_string($data['preset'] ?? null) && (isset(self::PRESETS[$data['preset']]) || 'CUSTOM' === $data['preset']) ? $data['preset'] : 'YES_NO';
        [$presetTrue, $presetFalse] = self::PRESETS[$preset] ?? self::PRESETS['YES_NO'];

        // Libellés manquants complétés depuis le preset
        $trueLabel = Coerce::stringOrNull($data['trueLabel'] ?? null, self::LABEL_MAX_LENGTH) ?? $presetTrue;
        $falseLabel = Coerce::stringOrNull($data['falseLabel'] ?? null, self::LABEL_MAX_LENGTH) ?? $presetFalse;

        // Le preset est recalculé d'après les libellés effectifs
        $preset = 'CUSTOM';
        foreach (self::PRESETS as $name => [$true, $false]) {
            if ($trueLabel === $true && $falseLabel === $false) {
                $preset = $name;
                break;
            }
        }

        $widget = \in_array($data['widget'] ?? null, self::WIDGETS, true) ? $data['widget'] : 'buttons';
        $default = $data['defaultValue'] ?? null;
        $defaultValue = null === $default || '' === $default ? null : Coerce::bool($default);
        if ('switch' === $widget && null === $defaultValue) {
            $defaultValue = false;
        }

        return new self($preset, $trueLabel, $falseLabel, $widget, $defaultValue);
    }

    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'trueLabel' => $this->trueLabel,
            'falseLabel' => $this->falseLabel,
            'widget' => $this->widget,
            'defaultValue' => $this->defaultValue,
        ];
    }

    public function violations(): array
    {
        $errors = [];

        if ('' === trim($this->trueLabel) || '' === trim($this->falseLabel)) {
            $errors[] = 'Les deux libellés (vrai et faux) sont obligatoires.';
        } elseif (mb_strtolower($this->trueLabel) === mb_strtolower($this->falseLabel)) {
            $errors[] = 'Les deux libellés doivent être différents.';
        }

        return $errors;
    }

    public function labelOf(bool $value): string
    {
        return $value ? $this->trueLabel : $this->falseLabel;
    }
}
