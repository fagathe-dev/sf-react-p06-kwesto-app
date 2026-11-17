<?php

declare(strict_types=1);

namespace FormsCore\Entity;

use Doctrine\ORM\Mapping as ORM;
use FormsCore\Repository\UploadedAssetRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Fichier déposé par un participant pour une question FILE.
 *
 * Le fichier est téléversé avant la soumission : il reste « orphelin » (answer = null) tant que les
 * réponses ne sont pas validées, puis il est rattaché à la réponse. Les orphelins de plus de 24 h
 * sont purgés chaque nuit (specs_fonc §9.2).
 */
#[ORM\Entity(repositoryClass: UploadedAssetRepository::class)]
#[ORM\Table(name: 'uploaded_asset')]
#[ORM\Index(name: 'idx_asset_orphans', columns: ['answer_id', 'created_at'])]
class UploadedAsset
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['asset.read'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Survey $survey;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SurveyRecipient $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?SurveyAnswer $answer = null;

    #[ORM\Column(length: 255)]
    #[Groups(['asset.read'])]
    private string $originalName;

    /** Chemin relatif au dossier de stockage privé (UPLOAD_STORAGE_DIR). */
    #[ORM\Column(length: 255)]
    private string $storedPath;

    /** Type MIME réel, détecté sur le contenu (pas l'extension ni l'en-tête client). */
    #[ORM\Column(length: 127)]
    #[Groups(['asset.read'])]
    private string $mimeType;

    #[ORM\Column(length: 16)]
    #[Groups(['asset.read'])]
    private string $extension;

    /** Poids en octets. */
    #[ORM\Column]
    #[Groups(['asset.read'])]
    private int $size;

    #[ORM\Column]
    #[Groups(['asset.read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        SurveyRecipient $recipient,
        Question $question,
        string $originalName,
        string $storedPath,
        string $mimeType,
        string $extension,
        int $size,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->survey = $recipient->getSurvey();
        $this->recipient = $recipient;
        $this->question = $question;
        $this->originalName = mb_substr($originalName, 0, 255);
        $this->storedPath = $storedPath;
        $this->mimeType = $mimeType;
        $this->extension = mb_substr($extension, 0, 16);
        $this->size = $size;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSurvey(): Survey
    {
        return $this->survey;
    }

    public function getQuestion(): Question
    {
        return $this->question;
    }

    public function getRecipient(): SurveyRecipient
    {
        return $this->recipient;
    }

    public function getAnswer(): ?SurveyAnswer
    {
        return $this->answer;
    }

    public function attachTo(SurveyAnswer $answer): static
    {
        $this->answer = $answer;

        return $this;
    }

    public function isOrphan(): bool
    {
        return null === $this->answer;
    }

    public function getOriginalName(): string
    {
        return $this->originalName;
    }

    public function getStoredPath(): string
    {
        return $this->storedPath;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[Groups(['asset.read'])]
    public function getRecipientEmail(): string
    {
        return $this->recipient->getEmail();
    }

    #[Groups(['asset.read'])]
    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }
}
