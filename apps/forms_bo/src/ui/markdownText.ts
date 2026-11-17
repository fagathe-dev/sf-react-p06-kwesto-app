import { parseMarkdown } from 'core-react/ui';

type Token = ReturnType<typeof parseMarkdown>[number];

const collectText = (tokens: Token[]): string =>
  tokens
    .map((token) => {
      if ('tokens' in token && Array.isArray(token.tokens)) return collectText(token.tokens as Token[]);
      if (token.type === 'list' && 'items' in token) return (token.items as Array<{ tokens: Token[] }>).map((item) => collectText(item.tokens)).join(' ');
      if (token.type === 'br' || token.type === 'space') return ' ';
      return 'text' in token && typeof token.text === 'string' ? token.text : '';
    })
    .join('');

/**
 * Texte brut d'une source Markdown (intitulé de question) : lignes repliées du builder, listes de choix,
 * annonces aux lecteurs d'écran. Miroir de FormsCore\Util\MarkdownText côté serveur.
 */
export const plainText = (source: string): string => collectText(parseMarkdown(source)).replace(/\s+/g, ' ').trim();
