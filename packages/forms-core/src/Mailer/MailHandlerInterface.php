<?php

declare(strict_types=1);

namespace FormsCore\Mailer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Handler d'e-mail (Fagathe\CorePhp\Mailer\AbstractMailerHandler) découvert automatiquement par MailDispatcher.
 *
 * Ajouter un e-mail = un DTO + un handler implémentant cette interface + un template (+ son aperçu) :
 * MailDispatcher n'est pas modifié.
 *
 * Les handlers vivent dans le namespace FormsCore\Mailer : core-php nomme le dossier de logs d'après
 * le dernier segment du namespace, ce qui range leurs journaux dans le canal « mailer ».
 */
#[AutoconfigureTag(self::TAG)]
interface MailHandlerInterface
{
    public const TAG = 'forms_core.mail_handler';

    /**
     * Classe du DTO pris en charge (clé du locator de MailDispatcher).
     *
     * @return class-string
     */
    public static function getDtoClass(): string;
}
