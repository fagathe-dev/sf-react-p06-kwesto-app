<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use FormsCore\DTO\Mail\ContactRequestMailDto;

final class ContactRequestMailPreview extends AbstractMailPreview
{
    public static function getSlug(): string
    {
        return 'contact-request';
    }

    public function getCode(): string
    {
        return 'CONTACT_REQUEST';
    }

    public function getLabel(): string
    {
        return 'Message de contact';
    }

    public function getDescription(): string
    {
        return 'Message du formulaire de contact du site vitrine, transmis à CONTACT_RECIPIENT_EMAIL.';
    }

    public function getAudience(): string
    {
        return 'Équipe kwesto';
    }

    public function getFields(): array
    {
        return [
            self::field('name', 'Nom', 'Sacha Lefèvre'),
            self::field('email', 'E-mail', 'sacha.lefevre@example.com', 'email'),
            self::field('subject', 'Objet', 'Question sur l\'offre Équipe'),
            self::field('message', 'Message', "Bonjour,\nNous souhaitons diffuser une enquête à 800 collaborateurs. L'offre Équipe couvre-t-elle ce volume ?\nMerci d'avance.", 'textarea'),
        ];
    }

    public function createDto(array $data = []): object
    {
        return new ContactRequestMailDto(
            name: $this->value($data, 'name'),
            email: $this->value($data, 'email'),
            subject: $this->value($data, 'subject'),
            message: $this->value($data, 'message'),
        );
    }
}
