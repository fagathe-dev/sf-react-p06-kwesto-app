<?php

declare(strict_types=1);

namespace FormsCore\Repository;

/**
 * Écritures standardisées des repositories : les services n'injectent jamais l'EntityManager.
 */
trait PersistenceTrait
{
    public function save(object $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(object $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Détache les entités chargées : à appeler entre deux lots d'un traitement de masse.
     */
    public function clear(): void
    {
        $this->getEntityManager()->clear();
    }

    /**
     * Pagination par page : une requête de comptage + une requête de résultats.
     * Réservé aux requêtes sans jointure sur une collection (les jointures to-one sont sûres).
     *
     * @return array{items: list<object>, total: int}
     */
    protected function paginateQuery(\Doctrine\ORM\QueryBuilder $qb, int $page, int $limit): array
    {
        $alias = $qb->getRootAliases()[0];

        $total = (int) (clone $qb)
            ->select(\sprintf('COUNT(%s.id)', $alias))
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $items = 0 === $total ? [] : $qb
            ->setFirstResult((max(1, $page) - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['items' => array_values($items), 'total' => $total];
    }

    /**
     * Exécute le traitement dans une transaction : tout est validé, ou rien.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        return $this->getEntityManager()->wrapInTransaction($operation);
    }
}
