<?php

declare(strict_types=1);

namespace FormsCore\DTO\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * CREATOR_SURVEY_CLOSED — notification de clôture : motif, taux final, lien vers les statistiques.
 */
final readonly class CreatorSurveyClosedMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $creatorEmail,
        #[Assert\NotBlank]
        public string $creatorName,
        #[Assert\NotBlank]
        public string $surveyTitle,
        #[Assert\NotBlank]
        public string $closeReason,
        #[Assert\Range(min: 0, max: 100)]
        public float $finalRate,
        #[Assert\PositiveOrZero]
        public int $answeredCount,
        #[Assert\PositiveOrZero]
        public int $invitedCount,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        public string $statsUrl,
    ) {
    }
}
