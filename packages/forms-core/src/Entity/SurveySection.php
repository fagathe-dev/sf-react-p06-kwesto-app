<?php

declare(strict_types=1);

namespace FormsCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use FormsCore\DTO\DisplayCondition;
use FormsCore\Repository\SurveySectionRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Section d'un questionnaire (specs_fonc §3.4). À partir de 2 sections, le formulaire devient un stepper.
 */
#[ORM\Entity(repositoryClass: SurveySectionRepository::class)]
#[ORM\Table(name: 'survey_section')]
#[ORM\Index(name: 'idx_section_survey_position', columns: ['survey_id', 'position'])]
class SurveySection
{
    public const TITLE_MIN_LENGTH = 3;
    public const TITLE_MAX_LENGTH = 150;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['survey.read', 'survey.public'])]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'sections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Survey $survey = null;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    #[Groups(['survey.read', 'survey.public'])]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['survey.read', 'survey.public'])]
    private ?string $description = null;

    #[ORM\Column]
    #[Groups(['survey.read', 'survey.public'])]
    private int $position = 1;

    /**
     * Affichage conditionnel de toute la section (specs_fonc §3.5) : contrat DisplayCondition, la source étant
     * une question d'une section précédente ; null = toujours affichée.
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
