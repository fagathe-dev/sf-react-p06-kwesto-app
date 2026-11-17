<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

/**
 * Fusionne les valeurs personnalisées avec le jeu de données par défaut de l'aperçu.
 */
abstract class AbstractMailPreview implements MailPreviewInterface
{
    protected const RECIPIENT_EMAIL = 'camille.martin@example.com';
    protected const CREATOR_EMAIL = 'alex.durand@example.com';
    protected const CREATOR_NAME = 'Alex Durand';
    protected const SURVEY_TITLE = 'Baromètre de satisfaction interne 2026';

    /**
     * @return array{name: string, label: string, type: string, value: string}
     */
    protected static function field(string $name, string $label, string $value, string $type = 'text'): array
    {
        return ['name' => $name, 'label' => $label, 'type' => $type, 'value' => $value];
    }

    /**
     * Valeur personnalisée si elle est renseignée, sinon valeur par défaut du champ.
     *
     * @param array<string, mixed> $data
     */
    protected function value(array $data, string $name): string
    {
        $custom = $data[$name] ?? null;
        if (\is_scalar($custom) && '' !== trim((string) $custom)) {
            return mb_substr(trim((string) $custom), 0, 2000);
        }

        foreach ($this->getFields() as $field) {
            if ($field['name'] === $name) {
                return $field['value'];
            }
        }

        throw new \LogicException(\sprintf('Champ d\'aperçu inconnu : "%s".', $name));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function int(array $data, string $name): int
    {
        return max(0, (int) $this->value($data, $name));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function float(array $data, string $name): float
    {
        return min(100.0, max(0.0, (float) str_replace(',', '.', $this->value($data, $name))));
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function date(array $data, string $name): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($this->value($data, $name));
        } catch (\Exception) {
            return new \DateTimeImmutable('+5 days');
        }
    }

    protected static function dateValue(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier))->format('Y-m-d\TH:i');
    }
}
