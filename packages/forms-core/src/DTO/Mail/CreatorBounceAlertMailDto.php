<?php

declare(strict_types=1);

namespace FormsCore\DTO\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * CREATOR_BOUNCE_ALERT — adresse rejetée définitivement, exclue du calcul des 100 %.
 */
final readonly class CreatorBounceAlertMailDto
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
        public string $bouncedEmail,
        #[Assert\NotBlank]
        #[Assert\Url(requireTld: false)]
        public string $recipientsUrl,
    ) {
    }
}
