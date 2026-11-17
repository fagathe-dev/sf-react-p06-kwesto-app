import { styled } from 'styled-components';

/** Mise en page du formulaire participant, partagée avec l'aperçu du builder. */

export const Questions = styled.div`
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[4]};
`;

/**
 * Bandeau collé en haut de l'écran (sous la barre de navigation) : stepper puis progression.
 * Toujours visible pendant le défilement, y compris sur mobile.
 */
export const StickyProgress = styled.div`
  position: sticky;
  top: 0;
  z-index: 5;
  border-bottom: 1px solid ${({ theme }) => theme.colors.border.default};
  background: color-mix(in srgb, ${({ theme }) => theme.colors.background.body} 92%, transparent);
  backdrop-filter: blur(8px);
`;

export const StickyInner = styled.div`
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[2]};
  max-width: 760px;
  margin: 0 auto;
  padding: ${({ theme }) => `${theme.spacing[3]} ${theme.spacing[4]}`};

  @media (max-width: 600px) {
    padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[3]}`};
  }
`;

export const FormNav = styled.div`
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
  margin-top: ${({ theme }) => theme.spacing[5]};
`;
