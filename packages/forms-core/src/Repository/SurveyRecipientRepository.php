<?php

declare(strict_types=1);

namespace FormsCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use FormsCore\Entity\Survey;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Entity\User;
use FormsCore\Enum\RecipientStatus;
use FormsCore\Enum\SurveyStatus;
use FormsCore\Trait\FullTextSearchTrait;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<SurveyRecipient>
 */
class SurveyRecipientRepository extends ServiceEntityRepository
{
    use FullTextSearchTrait;
    use PersistenceTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveyRecipient::class);
    }

    public function findOneByToken(string $token): ?SurveyRecipient
    {
        // Format strict : 64 caractères hexadécimaux, sinon aucune requête
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return $this->findOneBy(['token' => $token]);
    }

    public function findOneInSurvey(Survey $survey, string $id): ?SurveyRecipient
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'survey' => $survey]);
    }

    /**
     * Relit le destinataire avec un verrou en écriture (SELECT … FOR UPDATE) : à appeler dans une
     * transaction, pour sérialiser deux soumissions concurrentes du même jeton.
     */
    public function lockForSubmission(SurveyRecipient $recipient): SurveyRecipient
    {
        $em = $this->getEntityManager();
        $em->refresh($recipient, LockMode::PESSIMISTIC_WRITE);

        return $recipient;
    }

    /**
     * @return list<string> adresses déjà présentes dans la liste de diffusion
     */
    public function findEmails(Survey $survey): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.email')
            ->andWhere('r.survey = :survey')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * @return list<SurveyRecipient>
     */
    public function findBySurvey(Survey $survey): array
    {
        return $this->findBy(['survey' => $survey], ['email' => 'ASC']);
    }

    /**
     * @param list<RecipientStatus> $statuses
     *
     * @return list<SurveyRecipient>
     */
    public function findBySurveyAndStatuses(Survey $survey, array $statuses): array
    {
        return $this->findBy(['survey' => $survey, 'status' => $statuses], ['email' => 'ASC']);
    }

    /**
     * Suivi des destinataires : liste paginée, filtrable par statut et par adresse.
     *
     * @return array{items: list<SurveyRecipient>, total: int}
     */
    public function paginate(Survey $survey, ?RecipientStatus $status, string $search, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.survey = :survey')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->orderBy('r.email', 'ASC');

        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        if ('' !== trim($search)) {
            // Une adresse se cherche par fragment (préfixe, domaine) : LIKE sur la saisie échappée
            $qb->andWhere('r.email LIKE :like')
                ->setParameter('like', $this->toLikePattern(mb_strtolower(trim(mb_substr($search, 0, 100)))));
        }

        return $this->paginateQuery($qb, $page, $limit);
    }

    /**
     * Compteurs par statut d'un sondage.
     *
     * @return array{total: int, valid: int, pending: int, sent: int, opened: int, answered: int, bounced: int}
     */
    public function counters(Survey $survey): array
    {
        return $this->countersForSurveys([$survey])[$survey->getId()->toRfc4122()];
    }

    /**
     * Compteurs par statut de plusieurs sondages, en une seule requête.
     *
     * @param list<Survey> $surveys
     *
     * @return array<string, array{total: int, valid: int, pending: int, sent: int, opened: int, answered: int, bounced: int}> indexé par UUID
     */
    public function countersForSurveys(array $surveys): array
    {
        $counters = [];
        foreach ($surveys as $survey) {
            $counters[$survey->getId()->toRfc4122()] = ['total' => 0, 'valid' => 0, 'pending' => 0, 'sent' => 0, 'opened' => 0, 'answered' => 0, 'bounced' => 0];
        }

        if ([] === $surveys) {
            return $counters;
        }

        $rows = $this->createQueryBuilder('r')
            ->select('s.id AS surveyId, r.status AS status, COUNT(r.id) AS total')
            ->innerJoin('r.survey', 's')
            // Un tableau d'entités à identifiant UUID n'est pas converti en binaire par Doctrine : identifiants explicites
            ->andWhere('r.survey IN (:surveys)')
            ->setParameter('surveys', array_map(static fn (Survey $survey): string => $survey->getId()->toBinary(), $surveys), ArrayParameterType::BINARY)
            ->groupBy('s.id, r.status')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $surveyId = self::uuidString($row['surveyId']);
            $status = $row['status'] instanceof RecipientStatus ? $row['status'] : RecipientStatus::from((string) $row['status']);
            $total = (int) $row['total'];

            $counters[$surveyId][strtolower($status->value)] = $total;
            $counters[$surveyId]['total'] += $total;
            // Destinataires valides : hors BOUNCED (base du taux de participation et de la clôture à 100 %)
            if (RecipientStatus::Bounced !== $status) {
                $counters[$surveyId]['valid'] += $total;
            }
        }

        return $counters;
    }

    /**
     * Invitations à (ré)essayer : destinataires PENDING de sondages ACTIVE, en file depuis assez longtemps.
     *
     * @return list<SurveyRecipient>
     */
    public function findPendingToRetry(\DateTimeImmutable $queuedBefore, int $limit = 200): array
    {
        return $this->createQueryBuilder('r')
            ->innerJoin('r.survey', 's')
            ->addSelect('s')
            ->andWhere('r.status = :pending')
            ->andWhere('s.status = :active')
            ->andWhere('r.createdAt <= :queuedBefore OR r.sendAttempts > 0')
            ->setParameter('pending', RecipientStatus::Pending)
            ->setParameter('active', SurveyStatus::Active)
            ->setParameter('queuedBefore', $queuedBefore)
            ->orderBy('r.sendAttempts', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Répartition technique des répondants (navigateur, système ou appareil).
     *
     * @param 'browserName'|'osName'|'deviceType' $field
     *
     * @return list<array{label: string, count: int}>
     */
    public function countAnsweredBy(Survey $survey, string $field): array
    {
        if (!\in_array($field, ['browserName', 'osName', 'deviceType'], true)) {
            throw new \InvalidArgumentException(\sprintf('Champ de télémétrie inconnu : "%s".', $field));
        }

        $rows = $this->createQueryBuilder('r')
            ->select(\sprintf('r.%s AS label, COUNT(r.id) AS total', $field))
            ->andWhere('r.survey = :survey')
            ->andWhere('r.status = :answered')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->setParameter('answered', RecipientStatus::Answered)
            ->groupBy(\sprintf('r.%s', $field))
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static function (array $row): array {
            $label = $row['label'] instanceof \BackedEnum ? $row['label']->value : $row['label'];

            return ['label' => null === $label || '' === $label ? 'Inconnu' : (string) $label, 'count' => (int) $row['total']];
        }, $rows);
    }

    /**
     * Temps moyen de remplissage, en secondes.
     */
    public function averageCompletionSeconds(Survey $survey): ?float
    {
        $average = $this->createQueryBuilder('r')
            ->select('AVG(r.completionSeconds)')
            ->andWhere('r.survey = :survey')
            ->andWhere('r.status = :answered')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->setParameter('answered', RecipientStatus::Answered)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $average ? null : (float) $average;
    }

    /**
     * Recherche d'un destinataire par adresse dans les sondages d'un créateur.
     *
     * @return list<SurveyRecipient>
     */
    public function searchFullText(User $owner, string $rawQuery, int $limit = 10): array
    {
        // "@" et "." sont des séparateurs de mots pour l'index : on cherche chaque fragment
        $query = $this->normalizeSearchQuery(str_replace(['@', '.'], ' ', $rawQuery));
        if ('' === $query) {
            return [];
        }

        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.survey', 's')
            ->addSelect('s')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->setMaxResults($limit);

        $booleanQuery = $this->toBooleanQuery($query);

        if ('' === $booleanQuery) {
            $qb->andWhere('r.email LIKE :like')
                ->setParameter('like', $this->toLikePattern(mb_strtolower(trim(mb_substr($rawQuery, 0, 100)))))
                ->orderBy('r.email', 'ASC');
        } else {
            $qb->addSelect('MATCH_AGAINST(r.email) AGAINST (:term BOOLEAN) AS HIDDEN score')
                ->andWhere('MATCH_AGAINST(r.email) AGAINST (:term BOOLEAN) > 0')
                ->setParameter('term', $booleanQuery)
                ->orderBy('score', 'DESC');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Identifiant d'un résultat scalaire : Uuid hydraté, binaire brut (16 octets) ou chaîne RFC 4122.
     */
    private static function uuidString(mixed $value): string
    {
        return match (true) {
            $value instanceof Uuid => $value->toRfc4122(),
            \is_string($value) && 16 === \strlen($value) => Uuid::fromBinary($value)->toRfc4122(),
            default => (string) $value,
        };
    }

    /**
     * Efface les brouillons de réponses d'un sondage (à la clôture : ils ne peuvent plus être envoyés).
     */
    public function clearDrafts(Survey $survey): int
    {
        return (int) $this->createQueryBuilder('r')
            ->update()
            ->set('r.draftAnswers', ':null')
            ->set('r.draftQuestionId', ':null')
            ->set('r.draftSavedAt', ':null')
            ->andWhere('r.survey = :survey')
            ->andWhere('r.draftSavedAt IS NOT NULL')
            ->setParameter('null', null)
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime en masse des destinataires d'un sondage (liste de diffusion, avant le lancement).
     *
     * @param list<string> $ids UUID
     */
    public function deleteByIds(Survey $survey, array $ids): int
    {
        $binaryIds = [];
        foreach ($ids as $id) {
            if (Uuid::isValid($id)) {
                $binaryIds[] = Uuid::fromString($id)->toBinary();
            }
        }

        if ([] === $binaryIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->delete()
            ->andWhere('r.survey = :survey')
            ->andWhere('r.id IN (:ids)')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->setParameter('ids', $binaryIds, ArrayParameterType::BINARY)
            ->getQuery()
            ->execute();
    }
}
