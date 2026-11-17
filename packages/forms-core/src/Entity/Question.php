<?php

declare(strict_types=1);

namespace FormsCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use FormsCore\DTO\DisplayCondition;
use FormsCore\Enum\QuestionType;
use FormsCore\Repository\QuestionRepository;
use FormsCore\Util\MarkdownText;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Question d'un questionnaire : propriétés communes (specs_fonc §3.2) + configuration polymorphe
 * selon le type (specs_fonc §4, contrats FormsCore\DTO\QuestionConfig\*).
 */
#[ORM\Entity(repositoryClass: QuestionRepository::class)]
#[ORM\Table(name: 'question')]
#[ORM\Index(name: 'idx_question_survey_position', columns: ['survey_id', 'position'])]
#[ORM\Index(name: 'ft_question_search', columns: ['label', 'help_text'], flags: ['fulltext'])]
class Question
{
    public const LABEL_MAX_LENGTH = 500;
    public const HELP_TITLE_MAX_LENGTH = 80;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['survey.read', 'survey.public', 'question.search'])]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'questions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Survey $survey = null;

    /** Null tant que le questionnaire n'est pas découpé en sections. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SurveySection $section = null;

    #[ORM\Column(length: 16, enumType: QuestionType::class)]
    #[Groups(['survey.read', 'survey.public', 'question.search'])]
    private QuestionType $type = QuestionType::Text;

    #[ORM\Column(length: self::LABEL_MAX_LENGTH)]
    #[Groups(['survey.read', 'survey.public', 'question.search'])]
    private string $label = '';

    #[ORM\Column(length: self::HELP_TITLE_MAX_LENGTH, nullable: true)]
    #[Groups(['survey.read', 'survey.public'])]
    private ?string $helpTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['survey.read', 'survey.public', 'question.search'])]
    private ?string $helpText = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['survey.read', 'survey.public'])]
    private bool $required = true;

    /** Numérotation continue sur tout le questionnaire, à partir de 1. */
    #[ORM\Column]
    #[Groups(['survey.read', 'survey.public', 'question.search'])]
    private int $position = 1;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['survey.read', 'survey.public'])]
    private array $config = [];

    /**
     * Affichage conditionnel (specs_fonc §3.5) : contrat DisplayCondition, null = toujours affichée.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['survey.read', 'survey.public'])]
    private ?array $displayCondition = null;

    /**
     * @param Uuid|null $id identifiant généré par le builder (stable entre deux sauvegardes), sinon UUID v7
     */
    public function __construct(?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSurvey(): ?Survey
    {
        return $this->survey;
    }

    public function setSurvey(Survey $survey): static
    {
        $this->survey = $survey;

        return $this;
    }

    public function getSection(): ?SurveySection
    {
        return $this->section;
    }

    public function setSection(?SurveySection $section): static
    {
        $this->section = $section;

        return $this;
    }

    #[Groups(['survey.read', 'survey.public'])]
    public function getSectionId(): ?Uuid
    {
        return $this->section?->getId();
    }

    #[Groups(['question.search'])]
    public function getSurveyId(): ?Uuid
    {
        return $this->survey?->getId();
    }

    #[Groups(['question.search'])]
    public function getSurveyTitle(): ?string
    {
        return $this->survey?->getTitle();
    }

    public function getType(): QuestionType
    {
        return $this->type;
    }

    public function setType(QuestionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /** Intitulé sans Markdown (exports, archive, recherche). */
    public function getPlainLabel(): string
    {
        return MarkdownText::toPlain($this->label);
    }

    public function setLabel(string $label): static
    {
        $this->label = trim($label);

        return $this;
    }

    public function getHelpTitle(): ?string
    {
        return $this->helpTitle;
    }

    public function setHelpTitle(?string $helpTitle): static
    {
        $helpTitle = null !== $helpTitle ? trim($helpTitle) : null;
        $this->helpTitle = '' === $helpTitle ? null : $helpTitle;

        return $this;
    }

    public function getHelpText(): ?string
    {
        return $this->helpText;
    }

    public function setHelpText(?string $helpText): static
    {
        $helpText = null !== $helpText ? trim($helpText) : null;
        $this->helpText = '' === $helpText ? null : $helpText;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDisplayCondition(): ?array
    {
        return $this->displayCondition;
    }

    public function getCondition(): ?DisplayCondition
    {
        return DisplayCondition::fromArray($this->displayCondition);
    }

    public function setCondition(?DisplayCondition $condition): static
    {
        $this->displayCondition = $condition?->toArray();

        return $this;
    }
}
