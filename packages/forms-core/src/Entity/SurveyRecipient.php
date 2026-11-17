<?php

declare(strict_types=1);

namespace FormsCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use FormsCore\Enum\DeviceType;
use FormsCore\Enum\RecipientStatus;
use FormsCore\Repository\SurveyRecipientRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Destinataire d'une campagne : adresse e-mail, jeton d'accès personnel, statut de suivi (specs_fonc §5.4)
 * et télémétrie enregistrée à la soumission (specs_fonc §6.3).
 */
#[ORM\Entity(repositoryClass: SurveyRecipientRepository::class)]
#[ORM\Table(name: 'survey_recipient')]
#[ORM\UniqueConstraint(name: 'uniq_recipient_survey_email', columns: ['survey_id', 'email'])]
#[ORM\UniqueConstraint(name: 'uniq_recipient_token', columns: ['token'])]
#[ORM\Index(name: 'idx_recipient_survey_status', columns: ['survey_id', 'status'])]
#[ORM\Index(name: 'ft_recipient_search', columns: ['email'], flags: ['fulltext'])]
class SurveyRecipient
{
    public const TOKEN_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['recipient.read', 'recipient.search'])]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'recipients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Survey $survey;

    #[ORM\Column(length: 254)]
    #[Groups(['recipient.read', 'recipient.search'])]
    private string $email;

    /** Jeton unique de 64 caractères hexadécimaux, généré au lancement de la campagne (specs_fonc §5.3). */
    #[ORM\Column(length: self::TOKEN_LENGTH, nullable: true, options: ['fixed' => true])]
    private ?string $token = null;

    #[ORM\Column(length: 16, enumType: RecipientStatus::class)]
    #[Groups(['recipient.read', 'recipient.search'])]
    private RecipientStatus $status = RecipientStatus::Pending;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipient.read'])]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipient.read'])]
    private ?\DateTimeImmutable $openedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipient.read'])]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipient.read'])]
    private ?\DateTimeImmutable $reminderSentAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['recipient.read'])]
    private ?\DateTimeImmutable $bouncedAt = null;

    /** Nombre de tentatives d'envoi de l'invitation (rattrapage SMTP, specs_fonc §9.1). */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['recipient.read'])]
    private int $sendAttempts = 0;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['recipient.read'])]
    private ?string $lastError = null;

    // --- Télémétrie (renseignée à la soumission) ---

    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['recipient.read'])]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['recipient.read'])]
    private ?string $userAgentRaw = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['recipient.read'])]
    private ?string $browserName = null;

    #[ORM\Column(length: 16, nullable: true)]
    #[Groups(['recipient.read'])]
    private ?string $browserVersion = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Groups(['recipient.read'])]
    private ?string $osName = null;

    #[ORM\Column(length: 16, nullable: true, enumType: DeviceType::class)]
    #[Groups(['recipient.read'])]
    private ?DeviceType $deviceType = null;

    /** Durée de complétion en secondes : submittedAt - openedAt. */
    #[ORM\Column(nullable: true)]
    #[Groups(['recipient.read'])]
    private ?int $completionSeconds = null;

    #[ORM\Column]
    #[Groups(['recipient.read'])]
    private \DateTimeImmutable $createdAt;

    /**
     * Brouillon (specs_fonc §6.5) : réponses en cours, telles que saisies (non validées), pour reprendre plus
     * tard avec le même lien. Jamais exposé au créateur ni compté dans les statistiques ; effacé à l'envoi,
     * quand le participant recommence, et à la clôture du questionnaire.
     *
     * @var array<string, string|list<string>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $draftAnswers = null;

    /** Première question de l'écran où le participant s'est arrêté (reprise au même endroit). */
    #[ORM\Column(length: 36, nullable: true)]
    private ?string $draftQuestionId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $draftSavedAt = null;

    public function __construct(Survey $survey, string $email)
    {
        $this->id = Uuid::v7();
        $this->survey = $survey;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSurvey(): Survey
    {
        return $this->survey;
    }

    #[Groups(['recipient.search'])]
    public function getSurveyId(): Uuid
    {
        return $this->survey->getId();
    }

    #[Groups(['recipient.search'])]
    public function getSurveyTitle(): string
    {
        return $this->survey->getTitle();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $this->token = $token;

        return $this;
    }

    public function getStatus(): RecipientStatus
    {
        return $this->status;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function getBouncedAt(): ?\DateTimeImmutable
    {
        return $this->bouncedAt;
    }

    public function getSendAttempts(): int
    {
        return $this->sendAttempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // --- Transitions de statut ---

    public function markSent(\DateTimeImmutable $at): static
    {
        $this->status = RecipientStatus::Sent;
        $this->sentAt = $at;
        $this->lastError = null;
        ++$this->sendAttempts;

        return $this;
    }

    public function markSendFailed(string $error): static
    {
        ++$this->sendAttempts;
        $this->lastError = mb_substr($error, 0, 500);

        return $this;
    }

    public function markBounced(\DateTimeImmutable $at, string $error): static
    {
        $this->status = RecipientStatus::Bounced;
        $this->bouncedAt = $at;
        $this->lastError = mb_substr($error, 0, 500);

        return $this;
    }

    /**
     * Première ouverture du lien personnel : statut OPENED + horodatage (specs_fonc §6.1, cas 4).
     *
     * @return bool true si le statut vient de changer
     */
    public function markOpened(\DateTimeImmutable $at): bool
    {
        if (RecipientStatus::Sent !== $this->status) {
            return false;
        }

        $this->status = RecipientStatus::Opened;
        $this->openedAt = $at;

        return true;
    }

    /**
     * @param array<string, string|list<string>> $answers
     */
    public function saveDraft(array $answers, ?string $questionId, \DateTimeImmutable $at): static
    {
        $this->draftAnswers = $answers;
        $this->draftQuestionId = $questionId;
        $this->draftSavedAt = $at;

        return $this;
    }

    public function clearDraft(): static
    {
        $this->draftAnswers = null;
        $this->draftQuestionId = null;
        $this->draftSavedAt = null;

        return $this;
    }

    /**
     * @return array{answers: array<string, string|list<string>>, questionId: string|null, savedAt: string}|null
     */
    public function getDraft(): ?array
    {
        if (null === $this->draftAnswers || null === $this->draftSavedAt) {
            return null;
        }

        return [
            'answers' => $this->draftAnswers,
            'questionId' => $this->draftQuestionId,
            'savedAt' => $this->draftSavedAt->format(\DATE_ATOM),
        ];
    }

    public function markAnswered(\DateTimeImmutable $at): static
    {
        // Le brouillon n'a plus de raison d'être : les réponses validées sont enregistrées
        $this->clearDraft();
        $this->status = RecipientStatus::Answered;
        $this->submittedAt = $at;
        // Soumission sans ouverture enregistrée (cas limite) : durée nulle plutôt qu'indéfinie
        $this->openedAt ??= $at;
        $this->completionSeconds = max(0, $at->getTimestamp() - $this->openedAt->getTimestamp());

        return $this;
    }

    public function markReminded(\DateTimeImmutable $at): static
    {
        $this->reminderSentAt = $at;

        return $this;
    }

    // --- Télémétrie ---

    public function setTelemetry(
        ?string $ipAddress,
        ?string $userAgentRaw,
        ?string $browserName,
        ?string $browserVersion,
        ?string $osName,
        ?DeviceType $deviceType,
    ): static {
        $this->ipAddress = $ipAddress;
        $this->userAgentRaw = null !== $userAgentRaw ? mb_substr($userAgentRaw, 0, 2000) : null;
        $this->browserName = $browserName;
        $this->browserVersion = $browserVersion;
        $this->osName = $osName;
        $this->deviceType = $deviceType;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /**
     * Anonymisation RGPD (specs_fonc §9.2) : l'IP d'origine est remplacée de façon irréversible.
     */
    public function anonymizeIp(?string $anonymizedIp): static
    {
        $this->ipAddress = $anonymizedIp;

        return $this;
    }

    public function getUserAgentRaw(): ?string
    {
        return $this->userAgentRaw;
    }

    public function getBrowserName(): ?string
    {
        return $this->browserName;
    }

    public function getBrowserVersion(): ?string
    {
        return $this->browserVersion;
    }

    public function getOsName(): ?string
    {
        return $this->osName;
    }

    public function getDeviceType(): ?DeviceType
    {
        return $this->deviceType;
    }

    public function getCompletionSeconds(): ?int
    {
        return $this->completionSeconds;
    }
}
