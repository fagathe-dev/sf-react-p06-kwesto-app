<?php

declare(strict_types=1);

namespace FormsCore\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use FormsCore\Enum\CloseReason;
use FormsCore\Enum\SurveyDisplayMode;
use FormsCore\Enum\SurveyStatus;
use FormsCore\Repository\SurveyRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Questionnaire : métadonnées, cycle de vie (specs_fonc §2) et structure (sections, questions).
 */
#[ORM\Entity(repositoryClass: SurveyRepository::class)]
#[ORM\Table(name: 'survey')]
#[ORM\Index(name: 'idx_survey_status_expires', columns: ['status', 'expires_at'])]
#[ORM\Index(name: 'ft_survey_search', columns: ['title', 'description'], flags: ['fulltext'])]
class Survey
{
    public const TITLE_MIN_LENGTH = 3;
    public const TITLE_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['survey.list', 'survey.read', 'survey.public'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    #[Assert\NotBlank(message: 'Le titre est obligatoire.')]
    #[Assert\Length(
        min: self::TITLE_MIN_LENGTH,
        max: self::TITLE_MAX_LENGTH,
        minMessage: 'Le titre doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le titre ne peut pas dépasser {{ limit }} caractères.',
    )]
    #[Groups(['survey.list', 'survey.read', 'survey.public'])]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 10000, maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères.')]
    #[Groups(['survey.list', 'survey.read', 'survey.public'])]
    private ?string $description = null;

    #[ORM\Column(length: 16, enumType: SurveyStatus::class)]
    #[Groups(['survey.list', 'survey.read'])]
    private SurveyStatus $status = SurveyStatus::Draft;

    /** Présentation du formulaire participant : hors structure, modifiable tant que la campagne n'est pas lancée. */
    #[ORM\Column(length: 16, nullable: true, enumType: SurveyDisplayMode::class)]
    #[Groups(['survey.read', 'survey.public'])]
    private ?SurveyDisplayMode $displayMode = SurveyDisplayMode::List; // null (ligne antérieure) = LIST

    /**
     * Option du questionnaire : affiche au participant la durée estimée (calculée par forms_bo). Désactivable
     * pour un questionnaire long, afin de ne pas décourager les participants. Le nombre de questions est toujours affiché.
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['survey.read', 'survey.public'])]
    private ?bool $showDuration = true; // null (ligne antérieure) = affichée

    #[ORM\Column(nullable: true)]
    #[Groups(['survey.list', 'survey.read', 'survey.public'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['survey.read'])]
    private ?\DateTimeImmutable $validatedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['survey.list', 'survey.read'])]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['survey.list', 'survey.read'])]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(length: 24, nullable: true, enumType: CloseReason::class)]
    #[Groups(['survey.list', 'survey.read'])]
    private ?CloseReason $closeReason = null;

    /** Relance J-5/J-4 : une seule fois par campagne (specs_fonc §8). */
    #[ORM\Column(nullable: true)]
    #[Groups(['survey.read'])]
    private ?\DateTimeImmutable $reminderSentAt = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['survey.read'])]
    private int $remindersCount = 0;

    /**
     * Instantané des statistiques : figé à la clôture, ou pré-calculé la nuit pour les sondages actifs volumineux.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $statsSnapshot = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $statsComputedAt = null;

    /** Chemin (relatif au stockage) de l'archive ZIP pré-générée des pièces jointes. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $archivePath = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $telemetryAnonymizedAt = null;

    #[ORM\Column]
    #[Groups(['survey.list', 'survey.read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['survey.list', 'survey.read'])]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, SurveySection> */
    #[ORM\OneToMany(targetEntity: SurveySection::class, mappedBy: 'survey', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['survey.read', 'survey.public'])]
    private Collection $sections;

    /** @var Collection<int, Question> */
    #[ORM\OneToMany(targetEntity: Question::class, mappedBy: 'survey', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Groups(['survey.read', 'survey.public'])]
    private Collection $questions;

    /** @var Collection<int, SurveyRecipient> */
    #[ORM\OneToMany(targetEntity: SurveyRecipient::class, mappedBy: 'survey', cascade: ['remove'], fetch: 'EXTRA_LAZY')]
    private Collection $recipients;

    public function __construct(User $owner)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->sections = new ArrayCollection();
        $this->questions = new ArrayCollection();
        $this->recipients = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null !== $description ? trim($description) : null;
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function getStatus(): SurveyStatus
    {
        return $this->status;
    }

    public function setStatus(SurveyStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getDisplayMode(): SurveyDisplayMode
    {
        return $this->displayMode ?? SurveyDisplayMode::List;
    }

    public function setDisplayMode(SurveyDisplayMode $displayMode): static
    {
        $this->displayMode = $displayMode;

        return $this;
    }

    public function isShowDuration(): bool
    {
        return $this->showDuration ?? true;
    }

    public function setShowDuration(bool $showDuration): static
    {
        $this->showDuration = $showDuration;

        return $this;
    }

    public function isDraft(): bool
    {
        return SurveyStatus::Draft === $this->status;
    }

    public function isValidated(): bool
    {
        return SurveyStatus::Validated === $this->status;
    }

    public function isActive(): bool
    {
        return SurveyStatus::Active === $this->status;
    }

    public function isClosed(): bool
    {
        return SurveyStatus::Closed === $this->status;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    /**
     * Le formulaire participant n'accepte plus de réponse : clôturé ou date limite atteinte (specs_fonc §6.1, cas 2).
     */
    public function isOverAt(\DateTimeImmutable $now): bool
    {
        return $this->isClosed() || (null !== $this->expiresAt && $now >= $this->expiresAt);
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function setValidatedAt(?\DateTimeImmutable $validatedAt): static
    {
        $this->validatedAt = $validatedAt;

        return $this;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function setActivatedAt(?\DateTimeImmutable $activatedAt): static
    {
        $this->activatedAt = $activatedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getCloseReason(): ?CloseReason
    {
        return $this->closeReason;
    }

    public function setCloseReason(?CloseReason $closeReason): static
    {
        $this->closeReason = $closeReason;

        return $this;
    }

    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function markReminded(\DateTimeImmutable $at, int $count): static
    {
        $this->reminderSentAt = $at;
        $this->remindersCount = $count;

        return $this;
    }

    public function getRemindersCount(): int
    {
        return $this->remindersCount;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStatsSnapshot(): ?array
    {
        return $this->statsSnapshot;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    public function setStatsSnapshot(?array $snapshot, ?\DateTimeImmutable $computedAt): static
    {
        $this->statsSnapshot = $snapshot;
        $this->statsComputedAt = $computedAt;

        return $this;
    }

    public function getStatsComputedAt(): ?\DateTimeImmutable
    {
        return $this->statsComputedAt;
    }

    public function getArchivePath(): ?string
    {
        return $this->archivePath;
    }

    public function setArchivePath(?string $archivePath): static
    {
        $this->archivePath = $archivePath;

        return $this;
    }

    public function getTelemetryAnonymizedAt(): ?\DateTimeImmutable
    {
        return $this->telemetryAnonymizedAt;
    }

    public function setTelemetryAnonymizedAt(?\DateTimeImmutable $at): static
    {
        $this->telemetryAnonymizedAt = $at;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(?\DateTimeImmutable $at = null): static
    {
        $this->updatedAt = $at ?? new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return Collection<int, SurveySection>
     */
    public function getSections(): Collection
    {
        return $this->sections;
    }

    public function addSection(SurveySection $section): static
    {
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
            $section->setSurvey($this);
        }

        return $this;
    }

    public function removeSection(SurveySection $section): static
    {
        $this->sections->removeElement($section);

        return $this;
    }

    /**
     * @return Collection<int, Question>
     */
    public function getQuestions(): Collection
    {
        return $this->questions;
    }

    public function addQuestion(Question $question): static
    {
        if (!$this->questions->contains($question)) {
            $this->questions->add($question);
            $question->setSurvey($this);
        }

        return $this;
    }

    public function removeQuestion(Question $question): static
    {
        $this->questions->removeElement($question);

        return $this;
    }

    /**
     * Questions triées par position (ordre du formulaire participant et du rapport statistique).
     *
     * @return list<Question>
     */
    public function getOrderedQuestions(): array
    {
        $questions = $this->questions->toArray();
        usort($questions, static fn (Question $a, Question $b): int => $a->getPosition() <=> $b->getPosition());

        return $questions;
    }

    /**
     * @return list<SurveySection>
     */
    public function getOrderedSections(): array
    {
        $sections = $this->sections->toArray();
        usort($sections, static fn (SurveySection $a, SurveySection $b): int => $a->getPosition() <=> $b->getPosition());

        return $sections;
    }

    #[Groups(['survey.list', 'survey.read', 'survey.public'])]
    public function getQuestionsCount(): int
    {
        return $this->questions->count();
    }

    /**
     * Le formulaire participant devient un parcours par étapes à partir de 2 sections (specs_fonc §3.4).
     */
    #[Groups(['survey.read', 'survey.public'])]
    public function isStepper(): bool
    {
        return $this->sections->count() >= 2;
    }

    /**
     * @return Collection<int, SurveyRecipient>
     */
    public function getRecipients(): Collection
    {
        return $this->recipients;
    }
}
