<?php

declare(strict_types=1);

namespace FormsCore\Util;

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

/**
 * Nettoyage d'une liste de diffusion saisie à la main, collée ou importée d'un CSV (specs_fonc §5.1) :
 * minuscules, suppression des espaces, validation syntaxique RFC 5322, dédoublonnage.
 */
final class EmailListParser
{
    private const MAX_LENGTH = 254;

    /**
     * @param string|list<mixed> $input texte libre (séparateurs : retour à la ligne, virgule, point-virgule, espace)
     *                                  ou liste d'adresses
     *
     * @return array{valid: list<string>, invalid: list<string>, duplicates: int}
     */
    public static function parse(string|array $input): array
    {
        $text = \is_array($input)
            ? implode("\n", array_filter($input, static fn (mixed $value): bool => \is_string($value)))
            : $input;

        $validator = new EmailValidator();
        $rfc = new RFCValidation();

        $valid = [];
        $invalid = [];
        $duplicates = 0;

        foreach (preg_split('/[\s,;]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            // « Prénom Nom <adresse> », guillemets et crochets des exports de messagerie ou de tableur
            $candidate = mb_strtolower(trim($token, " \t\n\r\0\x0B\"'<>()[]"));

            // Une cellule sans « @ » (en-tête de colonne, nom, prénom) n'est pas une adresse : ignorée
            if (!str_contains($candidate, '@')) {
                continue;
            }

            if (mb_strlen($candidate) > self::MAX_LENGTH || !$validator->isValid($candidate, $rfc) || !self::hasDomainDot($candidate)) {
                $invalid[$candidate] = true;
                continue;
            }

            if (isset($valid[$candidate])) {
                ++$duplicates;
                continue;
            }

            $valid[$candidate] = true;
        }

        return [
            'valid' => array_map('strval', array_keys($valid)),
            'invalid' => array_map('strval', array_keys($invalid)),
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Une invitation ne peut être remise qu'à un domaine pleinement qualifié (pas « utilisateur@localhost »).
     */
    private static function hasDomainDot(string $email): bool
    {
        $domain = substr((string) strrchr($email, '@'), 1);

        return str_contains($domain, '.') && !str_starts_with($domain, '.') && !str_ends_with($domain, '.');
    }
}
