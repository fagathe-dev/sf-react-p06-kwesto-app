import { styled } from 'styled-components';

export interface BarItem {
  key: string;
  label: string;
  value: number;
  /** Texte affiché à droite (ex. « 12 · 40 % »). */
  caption?: string;
}

/**
 * Barres horizontales accessibles (une liste, pas un graphique opaque) : utilisées pour les
 * options d'une question CHOICE, les histogrammes et les répartitions techniques.
 */
export const BarList = ({ items, max, label }: { items: BarItem[]; max?: number; label: string }) => {
  const scale = max ?? Math.max(1, ...items.map((item) => item.value));

  return (
    <List aria-label={label}>
      {items.map((item) => (
        <li key={item.key}>
          <Head>
            <span>{item.label}</span>
            <strong>{item.caption ?? item.value}</strong>
          </Head>
          <Track aria-hidden="true">
            <Fill style={{ width: `${scale > 0 ? (item.value / scale) * 100 : 0}%` }} />
          </Track>
        </li>
      ))}
    </List>
  );
};

const List = styled.ul`
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[3]};
`;

const Head = styled.div`
  display: flex;
  justify-content: space-between;
  gap: ${({ theme }) => theme.spacing[3]};
  margin-bottom: ${({ theme }) => theme.spacing[1]};
  font-size: 0.875rem;

  span {
    overflow-wrap: anywhere;
  }

  strong {
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
  }
`;

const Track = styled.div`
  height: 10px;
  border-radius: ${({ theme }) => theme.radii.pill};
  background: ${({ theme }) => theme.colors.background.surfaceHover};
  overflow: hidden;
`;

const Fill = styled.div`
  height: 100%;
  border-radius: inherit;
  background: ${({ theme }) => theme.colors.primary.base};
  transition: width 0.3s ease;

  @media (prefers-reduced-motion: reduce) {
    transition: none;
  }
`;
