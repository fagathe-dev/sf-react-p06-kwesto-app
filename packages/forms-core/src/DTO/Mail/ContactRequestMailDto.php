<?php

declare(strict_types=1);

namespace FormsCore\DTO\Mail;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Message du formulaire de contact du site vitrine, transmis à CONTACT_RECIPIENT_EMAIL.
 */
final readonly class ContactRequestMailDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
        #[Assert\Length(max: 100, maxMessage: 'Le nom ne peut pas dépasser {{ limit }} caractères.')]
        public string $name,
        #[Assert\NotBlank(message: 'L\'adresse e-mail est obligatoire.')]
        #[Assert\Email(message: 'L\'adresse e-mail est invalide.')]
        public string $email,
        #[Assert\NotBlank(message: 'L\'objet est obligatoire.')]
        #[Assert\Length(max: 150, maxMessage: 'L\'objet ne peut pas dépasser {{ limit }} caractères.')]
        public string $subject,
        #[Assert\NotBlank(message: 'Le message est obligatoire.')]
        #[Assert\Length(
            min: 10,
            max: 5000,
            minMessage: 'Le message doit contenir au moins {{ limit }} caractères.',
            maxMessage: 'Le message ne peut pas dépasser {{ limit }} caractères.',
        )]
        public string $message,
    ) {
    }
}
