<?php

declare(strict_types=1);

namespace FormsCore\DTO\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * SURVEY_INVITATION — invitation personnelle envoyée au lancement de la campagne.
 */
final readonly class SurveyInvitationMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $recipientEmail,
        #[Assert\NotBlank]
        public string $surveyTitle,
        public ?string $surveyDescription,
        #[Assert\PositiveOrZero]
        public int $questionsCount,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        public string $surveyUrl,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
