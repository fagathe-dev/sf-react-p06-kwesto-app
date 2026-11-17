<?php

declare(strict_types=1);

namespace FormsCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use FormsCore\Entity\Survey;
use FormsCore\Entity\User;
use FormsCore\Enum\CloseReason;
use FormsCore\Enum\QuestionType;
use FormsCore\Enum\SurveyStatus;
use FormsCore\Trait\FullTextSearchTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Survey>
 */
class SurveyRepository extends ServiceEntityRepository
{
    use FullTextSearchTrait;
    use PersistenceTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Survey::class);
    }

    public function findOneById(string $id): ?Survey
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * Questionnaires d'un créateur, les plus récemment modifiés d'abord.
     *
     * @return array{items: list<Survey>, total: int}
     */
    public function paginateForOwner(User $owner, ?SurveyStatus $status, string $search, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->orderBy('s.updatedAt', 'DESC');

        if (null !== $status) {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }

        $this->applySearch($qb, $search);

        return $this->paginateQuery($qb, $page, $limit);
    }

    /**
     * Nombre de questionnaires par statut pour un créateur.
     *
     * @return array<string, int> statut => total
     */
    public function countByStatus(User $owner): array
    {
        $rows = $this->createQueryBuilder('s')
            ->select('s.status AS status, COUNT(s.id) AS total')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->groupBy('s.status')
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys(array_map(static fn (SurveyStatus $status): string => $status->value, SurveyStatus::cases()), 0);
        foreach ($rows as $row) {
            $status = $row['status'] instanceof SurveyStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Recherche plein texte sur le titre et la description, triée par pertinence.
     *
     * @return list<Survey>
     */
    public function searchFullText(User $owner, string $query, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('s')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setMaxResults($limit);

        if (!$this->applySearch($qb, $query, withScore: true)) {
            return [];
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Sondages ACTIVE dont la date limite est atteinte (clôture DEADLINE_REACHED).
     *
     * @return list<Survey>
     */
    public function findExpiredActive(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :active')
            ->andWhere('s.expiresAt <= :now')
            ->setParameter('active', SurveyStatus::Active)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    /**
     * Candidats à la relance de faible participation : fenêtre [expiresAt - 5 j, expiresAt - 4 j],
     * lancés depuis au moins 48 h, jamais relancés (specs_fonc §9.1). Le taux de réponse est vérifié par l'appelant.
     *
     * @return list<Survey>
     */
    public function findReminderCandidates(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :active')
            ->andWhere('s.reminderSentAt IS NULL')
            ->andWhere('s.expiresAt >= :windowStart')
            ->andWhere('s.expiresAt <= :windowEnd')
            ->andWhere('s.activatedAt <= :launchedBefore')
            ->setParameter('active', SurveyStatus::Active)
            ->setParameter('windowStart', $now->modify('+4 days'))
            ->setParameter('windowEnd', $now->modify('+5 days'))
            ->setParameter('launchedBefore', $now->modify('-48 hours'))
            ->getQuery()
            ->getResult();
    }

    /**
     * Passe le sondage de ACTIVE à CLOSED en une seule requête conditionnelle : si deux processus
     * tentent la clôture (dernière soumission + cron), un seul obtient true et notifie le créateur.
     */
    public function closeIfActive(Survey $survey, CloseReason $reason, \DateTimeImmutable $at): bool
    {
        $affected = $this->createQueryBuilder('s')
            ->update()
            ->set('s.status', ':closed')
            ->set('s.closedAt', ':at')
            ->set('s.closeReason', ':reason')
            ->set('s.updatedAt', ':at')
            ->andWhere('s.id = :id')
            ->andWhere('s.status = :active')
            ->setParameter('closed', SurveyStatus::Closed)
            ->setParameter('active', SurveyStatus::Active)
            ->setParameter('reason', $reason)
            ->setParameter('at', $at)
            ->setParameter('id', $survey->getId(), UuidType::NAME)
            ->getQuery()
            ->execute();

        $this->getEntityManager()->refresh($survey);

        return $affected > 0;
    }

    /**
     * @return list<Survey>
     */
    public function findActive(): array
    {
        return $this->findBy(['status' => SurveyStatus::Active]);
    }

    /**
     * Sondages clôturés avant la date donnée dont la télémétrie n'est pas encore anonymisée.
     *
     * @return list<Survey>
     */
    public function findClosedToAnonymize(\DateTimeImmutable $closedBefore): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :closed')
            ->andWhere('s.closedAt <= :closedBefore')
            ->andWhere('s.telemetryAnonymizedAt IS NULL')
            ->setParameter('closed', SurveyStatus::Closed)
            ->setParameter('closedBefore', $closedBefore)
            ->getQuery()
            ->getResult();
    }

    /**
     * Sondages clôturés depuis la date donnée, contenant au moins une question FILE et sans archive ZIP.
     *
     * @return list<Survey>
     */
    public function findClosedWithoutArchive(\DateTimeImmutable $closedSince): array
    {
        return $this->createQueryBuilder('s')
            ->innerJoin('s.questions', 'q', 'WITH', 'q.type = :file')
            ->andWhere('s.status = :closed')
            ->andWhere('s.closedAt >= :closedSince')
            ->andWhere('s.archivePath IS NULL')
            ->setParameter('file', QuestionType::File)
            ->setParameter('closed', SurveyStatus::Closed)
            ->setParameter('closedSince', $closedSince)
            ->groupBy('s.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Brouillons sans modification depuis la date donnée (nettoyage hebdomadaire).
     *
     * @return list<Survey>
     */
    public function findStaleDrafts(\DateTimeImmutable $inactiveSince): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.status = :draft')
            ->andWhere('s.updatedAt < :inactiveSince')
            ->setParameter('draft', SurveyStatus::Draft)
            ->setParameter('inactiveSince', $inactiveSince)
            ->getQuery()
            ->getResult();
    }

    /**
     * Filtre plein texte (mode booléen) avec repli LIKE pour les termes plus courts que la taille indexée.
     *
     * @return bool false si la recherche est vide après normalisation
     */
    private function applySearch(QueryBuilder $qb, string $rawQuery, bool $withScore = false): bool
    {
        $query = $this->normalizeSearchQuery($rawQuery);
        if ('' === $query) {
            return false;
        }

        $booleanQuery = $this->toBooleanQuery($query);

        if ('' === $booleanQuery) {
            $qb->andWhere('s.title LIKE :like OR s.description LIKE :like')
                ->setParameter('like', $this->toLikePattern($query));

            return true;
        }

        $qb->andWhere('MATCH_AGAINST(s.title, s.description) AGAINST (:term BOOLEAN) > 0')
            ->setParameter('term', $booleanQuery);

        if ($withScore) {
            $qb->addSelect('MATCH_AGAINST(s.title, s.description) AGAINST (:term BOOLEAN) AS HIDDEN score')
                ->orderBy('score', 'DESC');
        }

        return true;
    }
}
