<?php

declare(strict_types=1);

namespace FormsCore\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use FormsCore\Repository\SurveyAnswerRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Réponse d'un destinataire à une question : payload JSON polymorphe (contrats FormsCore\DTO\AnswerPayload\*).
 *
 * Un payload JSON n'est pas indexable en FULLTEXT : les réponses TEXT sont recopiées, normalisées,
 * dans la colonne `search_text` qui porte l'index (specs_tech §3.5).
 */
#[ORM\Entity(repositoryClass: SurveyAnswerRepository::class)]
#[ORM\Table(name: 'survey_answer')]
#[ORM\UniqueConstraint(name: 'uniq_answer_recipient_question', columns: ['recipient_id', 'question_id'])]
#[ORM\Index(name: 'idx_answer_question', columns: ['question_id'])]
#[ORM\Index(name: 'ft_answer_search', columns: ['search_text'], flags: ['fulltext'])]
class SurveyAnswer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Survey $survey;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SurveyRecipient $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'payload', type: Types::JSON)]
    private array $payload;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $searchText;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(SurveyRecipient $recipient, Question $question, array $payload, ?string $searchText, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->survey = $recipient->getSurvey();
        $this->recipient = $recipient;
        $this->question = $question;
        $this->payload = $payload;
        $this->searchText = $searchText;
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

    public function getRecipient(): SurveyRecipient
    {
        return $this->recipient;
    }

    public function getQuestion(): Question
    {
        return $this->question;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getSearchText(): ?string
    {
        return $this->searchText;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
