import { Icon } from 'core-react/icons';
import type { ReactNode } from 'react';
import { css, styled } from 'styled-components';

export type HelpBoxTone = 'info' | 'warning';

interface HelpBoxProps {
  /** Titre optionnel, en gras (`helpTitle`, max 80 caractères). */
  title?: string | null;
  children: ReactNode;
  /** `info` : aide contextuelle d'une question (seul style autorisé côté participant). `warning` : alerte du builder. */
  tone?: HelpBoxTone;
  /** Relie l'encart au champ via aria-describedby. */
  id?: string;
}

/**
 * Encart d'aide contextuelle (specs_fonc §3.2) : icône « info » toujours présente, titre optionnel,
 * texte simple (paragraphes et retours à la ligne, aucun HTML), aucun bouton, affiché en permanence.
 * Style unique : fond bleu clair, coins arrondis, texte bleu foncé.
 */
export const HelpBox = ({ title, children, tone = 'info', id }: HelpBoxProps) => (
  <Box id={id} $tone={tone} role="note">
    <Icon name={tone === 'warning' ? 'alert-outline' : 'information-outline'} size={20} aria-hidden="true" />
    <div>
      {title && <Title>{title}</Title>}
      <Body>{children}</Body>
    </div>
  </Box>
);

const Box = styled.div<{ $tone: HelpBoxTone }>`
  display: flex;
  gap: ${({ theme }) => theme.spacing[3]};
  align-items: flex-start;
  padding: ${({ theme }) => `${theme.spacing[3]} ${theme.spacing[4]}`};
  border-radius: ${({ theme }) => theme.radii.md};
  font-size: 0.9rem;
  line-height: 1.5;

  ${({ theme, $tone }) => {
    const role = $tone === 'warning' ? theme.colors.warning : theme.colors.info;
    return css`
      background: ${role.subtle};
      color: ${role.dark};
      border: 1px solid color-mix(in srgb, ${role.base} 25%, transparent);
    `;
  }}

  svg {
    flex-shrink: 0;
    margin-top: 1px;
  }
`;

const Title = styled.strong`
  display: block;
  margin-bottom: ${({ theme }) => theme.spacing[1]};
`;

/** Texte simple : les retours à la ligne sont conservés, aucun HTML n'est interprété. */
const Body = styled.div`
  white-space: pre-line;
  overflow-wrap: anywhere;
`;
