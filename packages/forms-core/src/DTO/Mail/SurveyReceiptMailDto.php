<?php

declare(strict_types=1);

namespace FormsCore\DTO\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * SURVEY_RECEIPT — accusé de réception horodaté, envoyé juste après la soumission.
 */
final readonly class SurveyReceiptMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $recipientEmail,
        #[Assert\NotBlank]
        public string $surveyTitle,
        public \DateTimeImmutable $submittedAt,
    ) {
    }
}
