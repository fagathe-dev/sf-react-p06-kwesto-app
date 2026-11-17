<?php

declare(strict_types=1);

namespace FormsCore\Service;

use Fagathe\CorePhp\File\MimeType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Configuration de la plateforme exposée aux interfaces (specs_tech §8.5).
 *
 * La limite d'upload vient uniquement de UPLOAD_MAX_SIZE_MB : le back-office la lit via l'endpoint
 * de configuration, jamais dans une variable VITE_*.
 */
final class PlatformConfig
{
    /** Formats proposés dans le builder pour une question FILE (tous présents dans MimeType de core-php). */
    private const FILE_FORMATS = [
        ['mimeType' => 'application/pdf', 'label' => 'PDF', 'extension' => 'pdf'],
        ['mimeType' => 'image/jpeg', 'label' => 'Image JPEG', 'extension' => 'jpg'],
        ['mimeType' => 'image/png', 'label' => 'Image PNG', 'extension' => 'png'],
        ['mimeType' => 'image/webp', 'label' => 'Image WebP', 'extension' => 'webp'],
        ['mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'label' => 'Word (.docx)', 'extension' => 'docx'],
        ['mimeType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'label' => 'Excel (.xlsx)', 'extension' => 'xlsx'],
        ['mimeType' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'label' => 'PowerPoint (.pptx)', 'extension' => 'pptx'],
        ['mimeType' => 'text/csv', 'label' => 'CSV', 'extension' => 'csv'],
        ['mimeType' => 'text/plain', 'label' => 'Texte (.txt)', 'extension' => 'txt'],
        ['mimeType' => 'application/zip', 'label' => 'Archive ZIP', 'extension' => 'zip'],
    ];

    public function __construct(
        #[Autowire(param: 'forms_core.upload.max_size_mb')]
        private readonly int $uploadMaxSizeMb,
    ) {
    }

    public function uploadMaxSizeMb(): int
    {
        return max(1, $this->uploadMaxSizeMb);
    }

    /**
     * @return list<array{mimeType: string, label: string, extension: string}>
     */
    public function fileFormats(): array
    {
        $supported = MimeType::getSupportedMimetypes();

        return array_values(array_filter(
            self::FILE_FORMATS,
            static fn (array $format): bool => \in_array($format['mimeType'], $supported, true),
        ));
    }

    /**
     * Plus petite des limites PHP (upload_max_filesize, post_max_size), en octets ; 0 = illimité.
     */
    public function phpUploadLimitBytes(): int
    {
        $limits = array_filter([
            self::iniBytes((string) \ini_get('upload_max_filesize')),
            self::iniBytes((string) \ini_get('post_max_size')),
        ]);

        return [] === $limits ? 0 : min($limits);
    }

    /**
     * upload_max_filesize et post_max_size doivent être supérieurs ou égaux à UPLOAD_MAX_SIZE_MB.
     */
    public function isPhpLimitConsistent(): bool
    {
        $phpLimit = $this->phpUploadLimitBytes();

        return 0 === $phpLimit || $phpLimit >= $this->uploadMaxSizeMb() * 1024 * 1024;
    }

    /**
     * @return array{uploadMaxSizeMb: int, fileFormats: list<array{mimeType: string, label: string, extension: string}>}
     */
    public function toArray(): array
    {
        return [
            'uploadMaxSizeMb' => $this->uploadMaxSizeMb(),
            'fileFormats' => $this->fileFormats(),
        ];
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ('' === $value || '0' === $value || '-1' === $value) {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower($value[-1])) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
