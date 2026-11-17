<?php

declare(strict_types=1);

namespace FormsCore\DTO\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Vitrine du layout et des composants d'e-mail prédéfinis de core-php (prévisualisation admin uniquement).
 */
final readonly class ComponentsShowcaseMailDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $recipientEmail,
        #[Assert\NotBlank]
        public string $headline,
    ) {
    }
}
