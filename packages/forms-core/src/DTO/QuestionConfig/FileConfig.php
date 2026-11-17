<?php

declare(strict_types=1);

namespace FormsCore\DTO\QuestionConfig;

use Fagathe\CorePhp\File\MimeType;
use FormsCore\DTO\Coerce;

/**
 * Configuration d'une question FILE (specs_fonc §4.5).
 *
 * `maxSizeMb` est plafonné par la limite globale de la plateforme (UPLOAD_MAX_SIZE_MB) : voir
 * FormsCore\QuestionType\FileHandler::normalizeConfig() et specs_tech §8.5.
 */
final readonly class FileConfig implements QuestionConfig
{
    public const DEFAULT_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];
    public const MAX_FILES_LIMIT = 10;

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        public array $allowedMimeTypes = self::DEFAULT_MIME_TYPES,
        public ?int $maxSizeMb = null,
        public int $maxFiles = 1,
    ) {
    }

    public static function fromArray(array $data): static
    {
        $supported = MimeType::getSupportedMimetypes();
        $mimeTypes = [];

        foreach (\is_array($data['allowedMimeTypes'] ?? null) ? $data['allowedMimeTypes'] : self::DEFAULT_MIME_TYPES as $mimeType) {
            if (\is_string($mimeType) && \in_array($mimeType, $supported, true) && !\in_array($mimeType, $mimeTypes, true)) {
                $mimeTypes[] = $mimeType;
            }
        }

        return new self(
            allowedMimeTypes: $mimeTypes,
            maxSizeMb: Coerce::intOrNull($data['maxSizeMb'] ?? null),
            maxFiles: Coerce::intOrNull($data['maxFiles'] ?? null) ?? 1,
        );
    }

    public function toArray(): array
    {
        return Coerce::withoutNulls([
            'allowedMimeTypes' => $this->allowedMimeTypes,
            'maxSizeMb' => $this->maxSizeMb,
            'maxFiles' => $this->maxFiles,
        ]);
    }

    public function violations(): array
    {
        $errors = [];

        if ([] === $this->allowedMimeTypes) {
            $errors[] = 'Au moins un format de fichier doit être autorisé.';
        }

        if (null !== $this->maxSizeMb && $this->maxSizeMb < 1) {
            $errors[] = 'Le poids maximal par fichier doit être d\'au moins 1 Mo.';
        }

        if ($this->maxFiles < 1 || $this->maxFiles > self::MAX_FILES_LIMIT) {
            $errors[] = \sprintf('Le nombre de fichiers doit être compris entre 1 et %d.', self::MAX_FILES_LIMIT);
        }

        return $errors;
    }

    /**
     * Applique le plafond de la plateforme : taille effective = min(maxSizeMb, limite globale).
     */
    public function cappedTo(int $platformMaxSizeMb): self
    {
        return new self(
            $this->allowedMimeTypes,
            null === $this->maxSizeMb ? $platformMaxSizeMb : min($this->maxSizeMb, $platformMaxSizeMb),
            $this->maxFiles,
        );
    }
}
