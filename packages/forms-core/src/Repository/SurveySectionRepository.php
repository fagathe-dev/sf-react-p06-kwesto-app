<?php

declare(strict_types=1);

namespace FormsCore\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use FormsCore\Entity\SurveySection;

/**
 * @extends ServiceEntityRepository<SurveySection>
 */
class SurveySectionRepository extends ServiceEntityRepository
{
    use PersistenceTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SurveySection::class);
    }
}
