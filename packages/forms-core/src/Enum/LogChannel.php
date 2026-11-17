<?php

declare(strict_types=1);

namespace FormsCore\Enum;

/**
 * Canaux des journaux applicatifs consultables dans l'espace Administration (specs_fonc §10.2).
 *
 * Un canal = un sous-dossier du dossier de logs de core-php (logs/<canal>/<fichier>-jj-mm-aaaa.json).
 */
enum LogChannel: string
{
    case Mailer = 'mailer';
    case Cron = 'cron';
    case App = 'app';
    case Security = 'security';
}
