<?php

declare(strict_types=1);

namespace FormsCore\DTO\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * CREATOR_LOW_RESPONSE — alerte de faible participation, envoyée en même temps que les relances.
 */
final readonly class CreatorLowResponseMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $creatorEmail,
        #[Assert\NotBlank]
        public string $creatorName,
        #[Assert\NotBlank]
        public string $surveyTitle,
        #[Assert\Range(min: 0, max: 100)]
        public float $responseRate,
        #[Assert\PositiveOrZero]
        public int $answeredCount,
        #[Assert\PositiveOrZero]
        public int $invitedCount,
        #[Assert\PositiveOrZero]
        public int $remindersSent,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        public string $recipientsUrl,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
