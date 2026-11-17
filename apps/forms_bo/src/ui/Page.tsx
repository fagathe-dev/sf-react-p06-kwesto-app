import { Loading } from 'core-react/ui';
import type { ReactNode } from 'react';
import { styled } from 'styled-components';

interface PageHeaderProps {
  title: ReactNode;
  subtitle?: ReactNode;
  actions?: ReactNode;
  eyebrow?: ReactNode;
  /** Texte d'introduction sous le titre (ex. description modifiable en place). */
  lead?: ReactNode;
}

/** En-tête de page : titre, introduction, sous-titre et actions alignées à droite. */
export const PageHeader = ({ title, subtitle, actions, eyebrow, lead }: PageHeaderProps) => (
  <Header>
    <TitleBlock>
      {eyebrow && <Eyebrow>{eyebrow}</Eyebrow>}
      <H1>{title}</H1>
      {lead && <Lead>{lead}</Lead>}
      {subtitle && <Subtitle>{subtitle}</Subtitle>}
    </TitleBlock>
    {actions && <Actions>{actions}</Actions>}
  </Header>
);

export const PageLoading = ({ label = 'Chargement…' }: { label?: string }) => (
  <Center>
    <Loading size="lg" label={label} />
  </Center>
);

const Header = styled.header`
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: ${({ theme }) => theme.spacing[4]};
  margin-bottom: ${({ theme }) => theme.spacing[5]};
`;

const TitleBlock = styled.div`
  flex: 1;
  min-width: min(100%, 320px);
`;

const Lead = styled.div`
  margin-top: ${({ theme }) => theme.spacing[1]};
`;

const Eyebrow = styled.div`
  margin-bottom: ${({ theme }) => theme.spacing[1]};
  font-size: 0.8rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const H1 = styled.h1`
  margin: 0;
  font-size: 1.6rem;
  line-height: 1.25;
  color: ${({ theme }) => theme.colors.text.heading};
`;

const Subtitle = styled.p`
  margin: ${({ theme }) => theme.spacing[2]} 0 0;
  font-size: 0.85rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const Actions = styled.div`
  display: flex;
  flex-wrap: wrap;
  gap: ${({ theme }) => theme.spacing[2]};
`;

const Center = styled.div`
  display: grid;
  min-height: 40vh;
  place-items: center;
`;
