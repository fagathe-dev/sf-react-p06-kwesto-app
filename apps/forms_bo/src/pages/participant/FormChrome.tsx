import { formatDateTime } from '@/utils/format';
import { Icon } from 'core-react/icons';
import { useEffect, useRef } from 'react';
import { styled } from 'styled-components';

/**
 * Habillage du formulaire participant, partagé par le vrai formulaire (/s/:token) et l'aperçu du builder :
 * en-tête, barre de progression, fil des étapes, titre d'étape.
 */

export const SurveyIntro = ({ title, description, expiresAt, estimate }: { title: string; description: string | null; expiresAt: string | null; estimate?: string | null }) => (
  <Header>
    <h1>{title}</h1>
    {description && <Description>{description}</Description>}
    {estimate && (
      <Deadline>
        <Icon name="clock-outline" size={16} /> {estimate}
      </Deadline>
    )}
    {expiresAt && (
      <Deadline>
        <Icon name="calendar-clock" size={16} /> Réponses acceptées jusqu'au {formatDateTime(expiresAt)}
      </Deadline>
    )}
  </Header>
);

export const ProgressBar = ({ label, percent }: { label: string; percent: number }) => (
  <div>
    <ProgressLabel>
      <span>{label}</span>
      <strong>{percent} %</strong>
    </ProgressLabel>
    <Track role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} aria-label="Progression du questionnaire">
      <Fill style={{ width: `${percent}%` }} />
    </Track>
  </div>
);

interface StepTrailProps {
  /** Sections du questionnaire : position (1, 2, 3…) et titre. */
  steps: Array<{ id: string; position: number; title: string }>;
  current: number;
  /** Aperçu : chaque section devient cliquable pour y aller directement. */
  onSelect?: (index: number) => void;
}

/**
 * Stepper des sections. Défile horizontalement s'il déborde ; sur mobile, seul le titre de la
 * section en cours est affiché à côté des numéros (les autres titres restent lus par les lecteurs d'écran).
 */
export const StepTrail = ({ steps, current, onSelect }: StepTrailProps) => {
  const listRef = useRef<HTMLOListElement>(null);

  // La section en cours reste visible quand le stepper déborde
  useEffect(() => {
    const item = listRef.current?.children[current] as HTMLElement | undefined;
    item?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
  }, [current]);

  return (
    <StepList ref={listRef} aria-label="Sections du questionnaire">
      {steps.map((step, index) => {
        const state = index < current ? 'done' : index === current ? 'current' : 'todo';
        const content = (
          <>
            <Bullet $state={state} aria-hidden="true">
              {step.position}
            </Bullet>
            <StepLabel $current={state === 'current'}>
              <VisuallyHidden>Section {step.position} : </VisuallyHidden>
              {step.title}
              {state === 'done' && <VisuallyHidden> (terminée)</VisuallyHidden>}
            </StepLabel>
          </>
        );

        return (
          <StepItem key={step.id} $state={state} aria-current={index === current ? 'step' : undefined}>
            {onSelect ? (
              <StepButton type="button" onClick={() => onSelect(index)}>
                {content}
              </StepButton>
            ) : (
              content
            )}
          </StepItem>
        );
      })}
    </StepList>
  );
};


export const StepHeading = ({ title, description }: { title: string; description: string | null }) => (
  <StepHeader>
    <h2>{title}</h2>
    {description && <Description>{description}</Description>}
  </StepHeader>
);

const Header = styled.header`
  margin-bottom: ${({ theme }) => theme.spacing[5]};

  h1 {
    margin: 0 0 ${({ theme }) => theme.spacing[2]};
    font-size: clamp(1.4rem, 4vw, 2rem);
    line-height: 1.2;
  }
`;

const Description = styled.p`
  margin: 0;
  white-space: pre-line;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const Deadline = styled.p`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1]};
  margin: ${({ theme }) => theme.spacing[3]} 0 0;
  font-size: 0.875rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const ProgressLabel = styled.div`
  display: flex;
  justify-content: space-between;
  margin-bottom: ${({ theme }) => theme.spacing[1]};
  font-size: 0.875rem;
`;

const Track = styled.div`
  height: 8px;
  border-radius: ${({ theme }) => theme.radii.pill};
  background: ${({ theme }) => theme.colors.border.default};
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

const StepList = styled.ol`
  display: flex;
  gap: ${({ theme }) => theme.spacing[1]};
  margin: 0;
  padding: 0;
  list-style: none;
  overflow-x: auto;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }
`;

type StepState = 'done' | 'current' | 'todo';

const StepItem = styled.li<{ $state: StepState }>`
  display: flex;
  flex-shrink: 0;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  padding: ${({ theme }) => `${theme.spacing[1]} ${theme.spacing[3]} ${theme.spacing[1]} ${theme.spacing[1]}`};
  border-radius: ${({ theme }) => theme.radii.pill};
  font-size: 0.85rem;
  font-weight: ${({ $state }) => ($state === 'current' ? 600 : 400)};
  white-space: nowrap;
  background: ${({ theme, $state }) => ($state === 'current' ? theme.colors.primary.subtle : 'transparent')};
  color: ${({ theme, $state }) => ($state === 'todo' ? theme.colors.text.muted : theme.colors.text.body)};

  @media (max-width: 600px) {
    padding-right: ${({ theme, $state }) => ($state === 'current' ? theme.spacing[3] : theme.spacing[1])};
  }
`;

/** Sur mobile, seul le titre de la section en cours est visible. */
const StepLabel = styled.span<{ $current: boolean }>`
  @media (max-width: 600px) {
    ${({ $current }) =>
      !$current &&
      `
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0 0 0 0);
      white-space: nowrap;
    `}
  }
`;

const VisuallyHidden = styled.span`
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
`;

const StepButton = styled.button`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;

  &:hover {
    text-decoration: underline;
  }

  &:focus-visible {
    outline: 2px solid ${({ theme }) => theme.colors.primary.base};
    outline-offset: 4px;
    border-radius: ${({ theme }) => theme.radii.pill};
  }
`;

const Bullet = styled.span<{ $state: StepState }>`
  display: inline-grid;
  flex-shrink: 0;
  place-items: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  font-size: 0.75rem;
  background: ${({ theme, $state }) => ($state === 'todo' ? theme.colors.border.default : theme.colors.primary.base)};
  color: ${({ theme, $state }) => ($state === 'todo' ? theme.colors.text.muted : theme.colors.white)};
`;

const StepHeader = styled.div`
  margin-bottom: ${({ theme }) => theme.spacing[4]};

  h2 {
    margin: 0 0 ${({ theme }) => theme.spacing[1]};
    font-size: 1.3rem;
  }
`;
