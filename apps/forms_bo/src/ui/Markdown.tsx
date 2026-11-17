import { MarkdownViewer, parseMarkdown, renderMarkdownTokens } from 'core-react/ui';
import { useMemo } from 'react';
import { styled } from 'styled-components';

/**
 * Intitulés et aides des questions saisis en Markdown (specs_fonc §3.2). Rendu par core-react :
 * aucun HTML injecté, seuls les liens http(s), mailto et relatifs sont actifs, pas d'images.
 */

type Token = ReturnType<typeof parseMarkdown>[number];

const RENDER = { allowImages: false, headingOffset: 0, linkTarget: '_blank' } as const;

/** Jetons en ligne d'une source : les blocs (paragraphes, titres, listes…) sont aplatis. */
const inlineTokens = (source: string): Token[] =>
  parseMarkdown(source).flatMap((token): Token[] => {
    if (token.type === 'space') return [];
    if ('tokens' in token && Array.isArray(token.tokens)) return token.tokens as Token[];
    if (token.type === 'list' && 'items' in token) return (token.items as Array<{ tokens: Token[] }>).flatMap((item) => item.tokens);
    return [token];
  });

/**
 * Intitulé de question : Markdown en ligne seulement (gras, italique, barré, surligné, code, lien),
 * car il est rendu dans un `<label>` ou une `<legend>`.
 */
export const InlineMarkdown = ({ source }: { source: string }) => {
  const nodes = useMemo(() => renderMarkdownTokens(inlineTokens(source), RENDER), [source]);
  return <>{nodes}</>;
};

/** Texte d'aide : Markdown complet (paragraphes, listes, citations, liens), retours à la ligne conservés. */
export const MarkdownBlock = ({ source }: { source: string }) => (
  <Block>
    <MarkdownViewer breaks headingOffset={2}>
      {source}
    </MarkdownViewer>
  </Block>
);

const Block = styled.div`
  p,
  ul,
  ol {
    margin: 0 0 ${({ theme }) => theme.spacing[1]};
  }

  > div > :last-child {
    margin-bottom: 0;
  }
`;
