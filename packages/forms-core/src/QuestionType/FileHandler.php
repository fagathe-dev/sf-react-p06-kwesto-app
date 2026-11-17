<?php

declare(strict_types=1);

namespace FormsCore\QuestionType;

use FormsCore\DTO\AnswerPayload\AnswerPayload;
use FormsCore\DTO\AnswerPayload\FileAnswer;
use FormsCore\DTO\QuestionConfig\FileConfig;
use FormsCore\Entity\Question;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Enum\QuestionType;
use FormsCore\Exception\InvalidAnswerException;
use FormsCore\Repository\UploadedAssetRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * FILE — dépôt de fichiers (specs_fonc §4.5).
 *
 * Le type MIME réel et le poids sont contrôlés au téléversement (Service\AssetService). La réponse
 * ne transporte que les identifiants des fichiers déjà déposés par ce destinataire.
 */
final class FileHandler implements QuestionTypeHandlerInterface
{
    public function __construct(
        private readonly UploadedAssetRepository $assetRepository,
        #[Autowire(param: 'forms_core.upload.max_size_mb')]
        private readonly int $platformMaxSizeMb,
    ) {
    }

    public static function getTypeValue(): string
    {
        return QuestionType::File->value;
    }

    /**
     * Le serveur plafonne maxSizeMb à la limite de la plateforme dès l'enregistrement (specs_tech §8.5).
     */
    public function normalizeConfig(array $raw): array
    {
        return FileConfig::fromArray($raw)->cappedTo($this->platformMaxSizeMb)->toArray();
    }

    public function validateConfig(array $config): array
    {
        return FileConfig::fromArray($config)->violations();
    }

    public function isEmptyAnswer(mixed $raw): bool
    {
        return [] === self::toIds($raw);
    }

    public function validateAnswer(Question $question, mixed $raw, SurveyRecipient $recipient): AnswerPayload
    {
        $ids = self::toIds($raw);
        $config = FileConfig::fromArray($question->getConfig());

        if (\count($ids) > $config->maxFiles) {
            throw new InvalidAnswerException(\sprintf('Vous pouvez déposer au maximum %d fichier(s).', $config->maxFiles));
        }

        foreach ($ids as $id) {
            if (!Uuid::isValid($id)) {
                throw new InvalidAnswerException('Un fichier déposé est introuvable. Merci de le déposer à nouveau.');
            }
        }

        // Fichiers téléversés par ce destinataire, pour cette question, pas encore rattachés à une réponse
        $assets = $this->assetRepository->findPendingForAnswer($recipient, $question, $ids);
        if (\count($assets) !== \count($ids)) {
            throw new InvalidAnswerException('Un fichier déposé est introuvable ou a expiré. Merci de le déposer à nouveau.');
        }

        return new FileAnswer($ids);
    }

    public function extractSearchText(array $payload): ?string
    {
        return null;
    }

    public function computeStats(Question $question, array $payloads): array
    {
        $formats = $this->assetRepository->countByFormat($question);

        return [
            'count' => \count($payloads),
            'totalFiles' => array_sum(array_column($formats, 'count')),
            'totalSize' => array_sum(array_column($formats, 'size')),
            'formats' => $formats,
        ];
    }

    public function exportValue(Question $question, array $payload): string
    {
        $ids = self::toIds($payload['assetIds'] ?? []);
        if ([] === $ids) {
            return '';
        }

        return implode(' | ', $this->assetRepository->findOriginalNames($ids));
    }

    /**
     * Taille effective en octets : min(question.maxSizeMb, limite de la plateforme).
     */
    public function effectiveMaxBytes(Question $question): int
    {
        return (int) FileConfig::fromArray($question->getConfig())->cappedTo($this->platformMaxSizeMb)->maxSizeMb * 1024 * 1024;
    }

    /**
     * @return list<string>
     */
    private static function toIds(mixed $raw): array
    {
        $ids = [];
        foreach (\is_array($raw) ? $raw : [] as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $ids[trim($value)] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }
}
