import { styled } from 'styled-components';

/** Primitives de mise en page partagées par les pages du dashboard. */

export const Stack = styled.div<{ $gap?: number }>`
  display: flex;
  flex-direction: column;
  gap: ${({ theme, $gap = 4 }) => theme.spacing[$gap as keyof typeof theme.spacing] ?? theme.spacing[4]};
`;

export const Row = styled.div<{ $gap?: number; $wrap?: boolean; $justify?: string }>`
  display: flex;
  align-items: center;
  flex-wrap: ${({ $wrap = true }) => ($wrap ? 'wrap' : 'nowrap')};
  justify-content: ${({ $justify = 'flex-start' }) => $justify};
  gap: ${({ theme, $gap = 2 }) => theme.spacing[$gap as keyof typeof theme.spacing] ?? theme.spacing[2]};
`;

export const Muted = styled.span`
  color: ${({ theme }) => theme.colors.text.muted};
  font-size: 0.875rem;
`;

export const SectionTitle = styled.h2`
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
  color: ${({ theme }) => theme.colors.text.heading};
`;

export const Panel = styled.section`
  background: ${({ theme }) => theme.colors.background.surface};
  border: 1px solid ${({ theme }) => theme.colors.border.default};
  border-radius: ${({ theme }) => theme.radii.lg};
  padding: ${({ theme }) => theme.spacing[5]};
`;
