<?php

declare(strict_types=1);

namespace FormsCore\Repository;

use Symfony\Bridge\Doctrine\Types\UuidType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use FormsCore\Entity\Question;
use FormsCore\Entity\Survey;
use FormsCore\Entity\User;
use FormsCore\Trait\FullTextSearchTrait;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Question>
 */
class QuestionRepository extends ServiceEntityRepository
{
    use FullTextSearchTrait;
    use PersistenceTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Question::class);
    }

    public function findOneInSurvey(Survey $survey, string $id): ?Question
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'survey' => $survey]);
    }

    /**
     * Recherche plein texte sur l'intitulé et l'aide des questions d'un créateur.
     *
     * @return list<Question>
     */
    public function searchFullText(User $owner, string $rawQuery, int $limit = 10): array
    {
        $query = $this->normalizeSearchQuery($rawQuery);
        if ('' === $query) {
            return [];
        }

        $qb = $this->createQueryBuilder('q')
            ->innerJoin('q.survey', 's')
            ->addSelect('s')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setMaxResults($limit);

        $booleanQuery = $this->toBooleanQuery($query);

        if ('' === $booleanQuery) {
            $qb->andWhere('q.label LIKE :like OR q.helpText LIKE :like')
                ->setParameter('like', $this->toLikePattern($query))
                ->orderBy('q.position', 'ASC');
        } else {
            $qb->addSelect('MATCH_AGAINST(q.label, q.helpText) AGAINST (:term BOOLEAN) AS HIDDEN score')
                ->andWhere('MATCH_AGAINST(q.label, q.helpText) AGAINST (:term BOOLEAN) > 0')
                ->setParameter('term', $booleanQuery)
                ->orderBy('score', 'DESC');
        }

        return $qb->getQuery()->getResult();
    }
}
