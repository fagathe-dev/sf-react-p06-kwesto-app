<?php

declare(strict_types=1);

namespace FormsCore\Repository;

use Symfony\Bridge\Doctrine\Types\UuidType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use FormsCore\Entity\Question;
use FormsCore\Entity\Survey;
use FormsCore\Entity\SurveyRecipient;
use FormsCore\Entity\UploadedAsset;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UploadedAsset>
 */
class UploadedAssetRepository extends ServiceEntityRepository
{
    use PersistenceTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UploadedAsset::class);
    }

    /**
     * Fichiers déposés par ce destinataire pour cette question, pas encore rattachés à une réponse.
     *
     * @param list<string>|null $ids restreint aux UUID donnés
     *
     * @return list<UploadedAsset>
     */
    public function findPendingForAnswer(SurveyRecipient $recipient, Question $question, ?array $ids = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.recipient = :recipient')
            ->andWhere('a.question = :question')
            ->andWhere('a.answer IS NULL')
            ->setParameter('recipient', $recipient->getId(), UuidType::NAME)
            ->setParameter('question', $question->getId(), UuidType::NAME)
            ->orderBy('a.createdAt', 'ASC');

        if (null !== $ids) {
            $binaryIds = self::toBinaryIds($ids);
            if ([] === $binaryIds) {
                return [];
            }

            $qb->andWhere('a.id IN (:ids)')->setParameter('ids', $binaryIds, ArrayParameterType::BINARY);
        }

        return $qb->getQuery()->getResult();
    }

    public function findPendingOne(SurveyRecipient $recipient, string $id): ?UploadedAsset
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'recipient' => $recipient, 'answer' => null]);
    }

    public function findOneInSurvey(Survey $survey, string $id): ?UploadedAsset
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $asset = $this->findOneBy(['id' => Uuid::fromString($id), 'survey' => $survey]);

        // Seuls les fichiers rattachés à une soumission validée sont consultables par le créateur
        return null !== $asset && !$asset->isOrphan() ? $asset : null;
    }

    /**
     * Répartition par format des fichiers rattachés aux réponses d'une question.
     *
     * @return list<array{extension: string, count: int, size: int}>
     */
    public function countByFormat(Question $question): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.extension AS extension, COUNT(a.id) AS total, SUM(a.size) AS size')
            ->andWhere('a.question = :question')
            ->andWhere('a.answer IS NOT NULL')
            ->setParameter('question', $question->getId(), UuidType::NAME)
            ->groupBy('a.extension')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'extension' => (string) $row['extension'],
            'count' => (int) $row['total'],
            'size' => (int) $row['size'],
        ], $rows);
    }

    /**
     * @param list<string> $ids UUID
     *
     * @return list<string>
     */
    public function findOriginalNames(array $ids): array
    {
        $binaryIds = self::toBinaryIds($ids);
        if ([] === $binaryIds) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->select('a.originalName')
            ->andWhere('a.id IN (:ids)')
            ->setParameter('ids', $binaryIds, ArrayParameterType::BINARY)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Galerie des fichiers d'une question (rattachés uniquement), paginée.
     *
     * @return array{items: list<UploadedAsset>, total: int}
     */
    public function paginateAttached(Question $question, int $page, int $limit): array
    {
        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.recipient', 'r')
            ->addSelect('r')
            ->andWhere('a.question = :question')
            ->andWhere('a.answer IS NOT NULL')
            ->setParameter('question', $question->getId(), UuidType::NAME)
            ->orderBy('a.createdAt', 'DESC');

        return $this->paginateQuery($qb, $page, $limit);
    }

    /**
     * Tous les fichiers rattachés d'un sondage (archive ZIP), triés par question puis destinataire.
     *
     * @return list<UploadedAsset>
     */
    public function findAttachedBySurvey(Survey $survey): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.question', 'q')
            ->innerJoin('a.recipient', 'r')
            ->addSelect('q', 'r')
            ->andWhere('a.survey = :survey')
            ->andWhere('a.answer IS NOT NULL')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->orderBy('q.position', 'ASC')
            ->addOrderBy('r.email', 'ASC')
            ->addOrderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countAttachedBySurvey(Survey $survey): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.survey = :survey')
            ->andWhere('a.answer IS NOT NULL')
            ->setParameter('survey', $survey->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Fichiers téléversés mais jamais rattachés à une soumission validée (purge nocturne).
     *
     * @return list<UploadedAsset>
     */
    public function findOrphansOlderThan(\DateTimeImmutable $before, int $limit = 500): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.answer IS NULL')
            ->andWhere('a.createdAt < :before')
            ->setParameter('before', $before)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<UploadedAsset>
     */
    public function findBySurvey(Survey $survey): array
    {
        return $this->findBy(['survey' => $survey]);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function toBinaryIds(array $ids): array
    {
        $binaryIds = [];
        foreach ($ids as $id) {
            if (\is_string($id) && Uuid::isValid($id)) {
                $binaryIds[] = Uuid::fromString($id)->toBinary();
            }
        }

        return $binaryIds;
    }
}
