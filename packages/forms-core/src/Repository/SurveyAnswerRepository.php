<?php

declare(strict_types=1);

namespace FormsCore\Repository;

use Symfony\Bridge\Doctrine\Types\UuidType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use FormsCore\Entity\Question;
use FormsCore\Entity\Survey;
use FormsCore\Entity\SurveyAnswer;
use FormsCore\Entity\User;
use FormsCore\Trait\FullTextSearchTrait;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SurveyAnswer>
 */
class SurveyAnswerRepository extends ServiceEntityRepository
{
    use FullTextSearchTrait;
    use PersistenceTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyAnswer::class);
    }

    /**
     * Payloads de toutes les réponses d'un sondage, groupés par question (calcul des statistiques).
     *
     * @return array<string, list<array<string, mixed>>> UUID de question => payloads
     */
    public function payloadsByQuestion(Survey $survey): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('q.id AS questionId, a.payload AS payload')
            ->innerJoin('a.question', 'q')
            ->andWhere('a.survey = :survey')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->getQuery()
            ->toIterable();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[self::uuid($row['questionId'])][] = (array) $row['payload'];
        }

        return $grouped;
    }

    /**
     * Payloads groupés par destinataire puis par question (export des réponses brutes).
     *
     * @return array<string, array<string, array<string, mixed>>> UUID de destinataire => UUID de question => payload
     */
    public function payloadsByRecipient(Survey $survey): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('r.id AS recipientId, q.id AS questionId, a.payload AS payload')
            ->innerJoin('a.question', 'q')
            ->innerJoin('a.recipient', 'r')
            ->andWhere('a.survey = :survey')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->getQuery()
            ->toIterable();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[self::uuid($row['recipientId'])][self::uuid($row['questionId'])] = (array) $row['payload'];
        }

        return $grouped;
    }

    /**
     * Verbatims d'une question TEXT, paginés, avec recherche par mot-clé (specs_fonc §4.1).
     *
     * @return array{items: list<array{id: string, text: string, createdAt: \DateTimeImmutable}>, total: int}
     */
    public function paginateVerbatims(Question $question, string $search, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.question = :question')
            ->andWhere('a.searchText IS NOT NULL')
            ->setParameter('question', $question->getId(), UuidType::NAME);

        if (!$this->applySearch($qb, $search)) {
            $qb->orderBy('a.createdAt', 'DESC');
        }

        $page = $this->paginateQuery($qb, $page, $limit);

        $items = [];
        /** @var SurveyAnswer $answer */
        foreach ($page['items'] as $answer) {
            $items[] = [
                'id' => $answer->getId()->toRfc4122(),
                'text' => (string) $answer->getSearchText(),
                'createdAt' => $answer->getCreatedAt(),
            ];
        }

        return ['items' => $items, 'total' => $page['total']];
    }

    /**
     * Recherche plein texte dans les verbatims des sondages d'un créateur.
     *
     * @return list<SurveyAnswer>
     */
    public function searchFullText(User $owner, string $rawQuery, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.survey', 's')
            ->innerJoin('a.question', 'q')
            ->addSelect('s', 'q')
            ->andWhere('s.owner = :owner')
            ->andWhere('a.searchText IS NOT NULL')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setMaxResults($limit);

        if (!$this->applySearch($qb, $rawQuery)) {
            return [];
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return bool false si la recherche est vide (aucun filtre appliqué)
     */
    private function applySearch(QueryBuilder $qb, string $rawQuery): bool
    {
        $query = $this->normalizeSearchQuery($rawQuery);
        if ('' === $query) {
            return false;
        }

        $booleanQuery = $this->toBooleanQuery($query);

        if ('' === $booleanQuery) {
            $qb->andWhere('a.searchText LIKE :like')
                ->setParameter('like', $this->toLikePattern($query))
                ->orderBy('a.createdAt', 'DESC');

            return true;
        }

        $qb->addSelect('MATCH_AGAINST(a.searchText) AGAINST (:term BOOLEAN) AS HIDDEN score')
            ->andWhere('MATCH_AGAINST(a.searchText) AGAINST (:term BOOLEAN) > 0')
            ->setParameter('term', $booleanQuery)
            ->orderBy('score', 'DESC');

        return true;
    }

    private static function uuid(mixed $value): string
    {
        return match (true) {
            $value instanceof Uuid => $value->toRfc4122(),
            \is_string($value) && 16 === \strlen($value) => Uuid::fromBinary($value)->toRfc4122(),
            default => (string) $value,
        };
    }
}
