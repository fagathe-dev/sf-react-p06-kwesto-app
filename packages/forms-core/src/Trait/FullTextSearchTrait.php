<?php

declare(strict_types=1);

namespace FormsCore\Trait;

/**
 * Préparation des termes pour MATCH … AGAINST en mode booléen (specs_tech §3.5).
 *
 * DQL : MATCH_AGAINST(alias.col1, alias.col2) AGAINST (:terme BOOLEAN), toujours avec un paramètre lié.
 */
trait FullTextSearchTrait
{
    /** Taille minimale d'un mot indexé par InnoDB (innodb_ft_min_token_size, 3 par défaut). */
    private const FULLTEXT_MIN_TOKEN_SIZE = 3;
    private const SEARCH_MAX_LENGTH = 100;

    /**
     * Normalise la saisie : trim, longueur maximale, retrait des opérateurs booléens.
     */
    protected function normalizeSearchQuery(string $rawQuery): string
    {
        $cleaned = preg_replace('/[+\-><()~*"@%_\\\\]+/u', ' ', mb_substr(trim($rawQuery), 0, self::SEARCH_MAX_LENGTH)) ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $cleaned));
    }

    /**
     * "symfony docker" → "+symfony* +docker*". Chaîne vide si aucun mot n'atteint la taille minimale
     * indexée : l'appelant bascule alors sur un repli LIKE.
     */
    protected function toBooleanQuery(string $normalizedQuery): string
    {
        $tokens = [];

        foreach (explode(' ', $normalizedQuery) as $word) {
            if (mb_strlen($word) >= self::FULLTEXT_MIN_TOKEN_SIZE) {
                $tokens[] = '+'.$word.'*';
            }
        }

        return implode(' ', $tokens);
    }

    /**
     * Motif LIKE échappé pour le repli sur les termes très courts.
     */
    protected function toLikePattern(string $normalizedQuery): string
    {
        return '%'.addcslashes($normalizedQuery, '%_\\').'%';
    }
}
