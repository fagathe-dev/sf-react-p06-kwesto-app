<?php

declare(strict_types=1);

namespace FormsCore\Util;

/**
 * Dates lisibles en français (e-mails, exports), dans le fuseau de la plateforme.
 */
final class DateFormatter
{
    public const TIMEZONE = 'Europe/Paris';

    /**
     * « vendredi 13 novembre 2026 à 18h00 »
     */
    public static function long(\DateTimeInterface $date): string
    {
        return self::format($date, "EEEE d MMMM y 'à' HH'h'mm");
    }

    /**
     * « 13/11/2026 18:00 »
     */
    public static function short(\DateTimeInterface $date): string
    {
        return self::format($date, 'dd/MM/y HH:mm');
    }

    private static function format(\DateTimeInterface $date, string $pattern): string
    {
        $formatter = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, self::TIMEZONE, null, $pattern);

        return (string) $formatter->format($date);
    }
}
