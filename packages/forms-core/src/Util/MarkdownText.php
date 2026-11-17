<?php

declare(strict_types=1);

namespace FormsCore\Util;

/**
 * Texte brut d'un intitulé ou d'une aide saisis en Markdown (specs_fonc §3.2) : exports CSV / Excel,
 * dossiers de l'archive ZIP, résultats de recherche. Le rendu Markdown reste l'affaire de forms_bo.
 */
final class MarkdownText
{
    public static function toPlain(string $markdown): string
    {
        $text = $markdown;

        // Liens et images : seul le texte est conservé
        $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        // Code en ligne, gras, italique, barré, surligné
        $text = (string) preg_replace('/`([^`]*)`/u', '$1', $text);
        $text = (string) preg_replace('/(\*\*|__)(.+?)\1/u', '$2', $text);
        $text = (string) preg_replace('/(?<![\w*])([*_])(?!\s)(.+?)(?<!\s)\1(?![\w*])/u', '$2', $text);
        $text = (string) preg_replace('/(~~|==)(.+?)\1/u', '$2', $text);
        // Marqueurs de début de ligne : titres, citations, listes
        $text = (string) preg_replace('/^\s{0,3}(#{1,6}\s+|>\s?|[-*+]\s+(\[[ xX]\]\s+)?|\d+[.)]\s+)/mu', '', $text);
        // Échappements Markdown
        $text = (string) preg_replace('/\\\\([\\\\`*_{}\[\]()#+\-.!~=|>])/u', '$1', $text);

        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
    }
}
