<?php

declare(strict_types=1);

namespace FormsCore\DTO\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * SURVEY_REMINDER — rappel de l'échéance aux destinataires qui n'ont pas répondu (une fois par campagne).
 */
final readonly class SurveyReminderMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $recipientEmail,
        #[Assert\NotBlank]
        public string $surveyTitle,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        public string $surveyUrl,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
