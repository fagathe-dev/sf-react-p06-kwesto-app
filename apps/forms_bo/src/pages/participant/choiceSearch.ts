/** À partir de ce nombre d'options, une question CHOICE propose une recherche (specs_fonc §4.3). */
export const CHOICE_SEARCH_THRESHOLD = 10;

/** Recherche insensible à la casse et aux accents. */
export const normalize = (text: string): string =>
  text
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .trim();

export const matches = (label: string, query: string): boolean => normalize(label).includes(normalize(query));
