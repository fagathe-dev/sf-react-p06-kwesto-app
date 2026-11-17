<?php

declare(strict_types=1);

namespace FormsCore\Mailer\Preview;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Aperçu d'un e-mail avec un jeu de données réaliste, personnalisable (aucun envoi), découvert
 * automatiquement par Service\EmailPreviewService (specs_fonc §10.1).
 *
 * Ajouter un aperçu = une classe implémentant cette interface : EmailPreviewService n'est pas modifié.
 */
#[AutoconfigureTag(self::TAG)]
interface MailPreviewInterface
{
    public const TAG = 'forms_core.mail_preview';

    /**
     * Identifiant d'URL (kebab-case), ex. "survey-invitation".
     */
    public static function getSlug(): string;

    /**
     * Code du catalogue des e-mails (specs_fonc §8), ex. "SURVEY_INVITATION".
     */
    public function getCode(): string;

    public function getLabel(): string;

    public function getDescription(): string;

    /**
     * Destinataire type : « Participant », « Créateur »…
     */
    public function getAudience(): string;

    /**
     * Champs du jeu de données, avec leur valeur par défaut.
     *
     * @return list<array{name: string, label: string, type: string, value: string}>
     */
    public function getFields(): array;

    /**
     * DTO transmis au handler de l'e-mail.
     *
     * @param array<string, mixed> $data valeurs personnalisées, indexées par nom de champ
     */
    public function createDto(array $data = []): object;
}
