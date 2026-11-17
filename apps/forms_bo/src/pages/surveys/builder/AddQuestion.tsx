import type { QuestionType } from '@/types';
import { Icon } from 'core-react/icons';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { css, styled } from 'styled-components';
import { QUESTION_TYPE_OPTIONS } from './questionTypeOptions';

interface TypePickerProps {
  onPick: (type: QuestionType) => void;
  onClose?: () => void;
  title?: string;
}

/** Choix du type de la nouvelle question : 5 cartes explicites, navigables au clavier. */
const TypePicker = ({ onPick, onClose, title = 'Quel type de question ?' }: TypePickerProps) => {
  const titleId = useId();
  const firstRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (onClose) firstRef.current?.focus();
  }, [onClose]);

  const onKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && onClose) {
      event.stopPropagation();
      onClose();
    }
  };

  return (
    <Picker role="group" aria-labelledby={titleId} onKeyDown={onKeyDown}>
      <PickerHead>
        <strong id={titleId}>{title}</strong>
        {onClose && (
          <CloseButton type="button" onClick={onClose} aria-label="Fermer le choix du type">
            <Icon name="close" size={18} />
          </CloseButton>
        )}
      </PickerHead>
      <Grid>
        {QUESTION_TYPE_OPTIONS.map(({ type, label, icon, description }, index) => (
          <TypeCard key={type} ref={index === 0 ? firstRef : undefined} type="button" onClick={() => onPick(type)}>
            <TypeIcon aria-hidden="true">
              <Icon name={icon} size={20} />
            </TypeIcon>
            <span>
              <TypeName>{label}</TypeName>
              <TypeDescription>{description}</TypeDescription>
            </span>
          </TypeCard>
        ))}
      </Grid>
    </Picker>
  );
};

/**
 * Fin d'une section (ou de la page unique) : bouton pleine largeur qui se déploie en sélecteur de type.
 * Ouvert d'emblée quand la section est vide.
 */
export const AddQuestionButton = ({ onAdd, empty = false }: { onAdd: (type: QuestionType) => void; empty?: boolean }) => {
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);

  if (empty) {
    return <TypePicker title="Ajoutez une première question" onPick={onAdd} />;
  }

  if (open) {
    return (
      <TypePicker
        onPick={(type) => {
          setOpen(false);
          onAdd(type);
        }}
        onClose={() => {
          setOpen(false);
          requestAnimationFrame(() => triggerRef.current?.focus());
        }}
      />
    );
  }

  return (
    <AddButton ref={triggerRef} type="button" onClick={() => setOpen(true)} aria-expanded={false}>
      <Plus aria-hidden="true">
        <Icon name="plus" size={18} />
      </Plus>
      Ajouter une question
    </AddButton>
  );
};

/**
 * Point d'insertion entre deux questions : un « + » discret qui apparaît au survol ou au focus clavier,
 * pour insérer une question à cet endroit précis.
 */
export const InsertSlot = ({ onAdd, after }: { onAdd: (type: QuestionType) => void; after: number }) => {
  const [open, setOpen] = useState(false);
  const triggerRef = useRef<HTMLButtonElement>(null);

  if (open) {
    return (
      <SlotPicker>
        <TypePicker
          title={`Insérer une question après la question ${after}`}
          onPick={(type) => {
            setOpen(false);
            onAdd(type);
          }}
          onClose={() => {
            setOpen(false);
            requestAnimationFrame(() => triggerRef.current?.focus());
          }}
        />
      </SlotPicker>
    );
  }

  return (
    <Slot>
      <SlotButton ref={triggerRef} type="button" onClick={() => setOpen(true)} aria-label={`Insérer une question après la question ${after}`}>
        <Icon name="plus" size={16} />
      </SlotButton>
    </Slot>
  );
};

/** Séparateur « Nouvelle section » : action secondaire, visible, placée après les questions. */
export const AddSectionDivider = ({ onAdd, sections }: { onAdd: () => void; sections: number }) => (
  <Divider>
    <SectionButton type="button" onClick={onAdd}>
      <Icon name="table-row-plus-after" size={18} /> Nouvelle section
    </SectionButton>
    <DividerHint>
      {sections === 0
        ? 'Découpez le questionnaire en sections : à partir de 2, le participant le remplit étape par étape.'
        : sections === 1
          ? 'Ajoutez une deuxième section pour transformer le formulaire en parcours par étapes.'
          : `${sections} sections · formulaire en étapes`}
    </DividerHint>
  </Divider>
);

const focusRing = css`
  &:focus-visible {
    outline: 2px solid ${({ theme }) => theme.colors.primary.base};
    outline-offset: 2px;
  }
`;

const AddButton = styled.button`
  ${focusRing}
  display: flex;
  align-items: center;
  justify-content: center;
  gap: ${({ theme }) => theme.spacing[2]};
  width: 100%;
  min-height: 56px;
  border: 2px dashed color-mix(in srgb, ${({ theme }) => theme.colors.primary.base} 45%, transparent);
  border-radius: ${({ theme }) => theme.radii.lg};
  background: color-mix(in srgb, ${({ theme }) => theme.colors.primary.subtle} 40%, transparent);
  color: ${({ theme }) => theme.colors.primary.base};
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease;

  &:hover {
    border-color: ${({ theme }) => theme.colors.primary.base};
    background: ${({ theme }) => theme.colors.primary.subtle};
  }
`;

const Plus = styled.span`
  display: inline-grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: ${({ theme }) => theme.colors.primary.base};
  color: ${({ theme }) => theme.colors.white};
`;

const Picker = styled.div`
  padding: ${({ theme }) => theme.spacing[4]};
  border: 2px solid ${({ theme }) => theme.colors.primary.base};
  border-radius: ${({ theme }) => theme.radii.lg};
  background: ${({ theme }) => theme.colors.background.surface};
  box-shadow: ${({ theme }) => theme.shadows.md};
`;

const PickerHead = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: ${({ theme }) => theme.spacing[3]};
  color: ${({ theme }) => theme.colors.text.heading};
`;

const CloseButton = styled.button`
  ${focusRing}
  display: inline-grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: ${({ theme }) => theme.colors.text.muted};
  cursor: pointer;

  &:hover {
    background: ${({ theme }) => theme.colors.background.surfaceHover};
  }
`;

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: ${({ theme }) => theme.spacing[2]};
`;

const TypeCard = styled.button`
  ${focusRing}
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
  padding: ${({ theme }) => theme.spacing[3]};
  border: 1px solid ${({ theme }) => theme.colors.border.default};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.body};
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;

  &:hover {
    border-color: ${({ theme }) => theme.colors.primary.base};
    background: ${({ theme }) => theme.colors.primary.subtle};
  }
`;

const TypeIcon = styled.span`
  display: inline-grid;
  flex-shrink: 0;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.primary.subtle};
  color: ${({ theme }) => theme.colors.primary.base};
`;

const TypeName = styled.span`
  display: block;
  font-weight: 600;
  color: ${({ theme }) => theme.colors.text.heading};
`;

const TypeDescription = styled.span`
  display: block;
  font-size: 0.8rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const Slot = styled.div`
  position: relative;
  display: flex;
  justify-content: center;
  height: 0;

  /* Zone de survol plus haute que le bouton, entre deux cartes */
  &::before {
    content: '';
    position: absolute;
    inset: -12px 0;
  }

  &::after {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    left: 0;
    height: 2px;
    background: ${({ theme }) => theme.colors.primary.base};
    opacity: 0;
    transition: opacity 0.15s ease;
  }

  &:hover::after,
  &:focus-within::after {
    opacity: 0.5;
  }
`;

const SlotButton = styled.button`
  ${focusRing}
  position: relative;
  z-index: 1;
  display: inline-grid;
  place-items: center;
  width: 28px;
  height: 28px;
  margin-top: -14px;
  border: 0;
  border-radius: 50%;
  background: ${({ theme }) => theme.colors.primary.base};
  color: ${({ theme }) => theme.colors.white};
  box-shadow: ${({ theme }) => theme.shadows.md};
  cursor: pointer;
  opacity: 0;
  transform: scale(0.8);
  transition:
    opacity 0.15s ease,
    transform 0.15s ease;

  ${Slot}:hover &,
  &:focus-visible {
    opacity: 1;
    transform: scale(1);
  }

  /* Écrans tactiles : pas de survol, le bouton reste visible */
  @media (hover: none) {
    opacity: 1;
    transform: scale(1);
  }

  @media (prefers-reduced-motion: reduce) {
    transition: none;
  }
`;

const SlotPicker = styled.div`
  margin: ${({ theme }) => theme.spacing[1]} 0;
`;

const Divider = styled.div`
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  margin-top: ${({ theme }) => theme.spacing[3]};
  padding-top: ${({ theme }) => theme.spacing[5]};
  border-top: 1px solid ${({ theme }) => theme.colors.border.default};
`;

const SectionButton = styled.button`
  ${focusRing}
  display: inline-flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  margin-top: calc(-${({ theme }) => theme.spacing[5]} - 20px);
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[4]}`};
  border: 1px solid ${({ theme }) => theme.colors.border.strong};
  border-radius: ${({ theme }) => theme.radii.pill};
  background: ${({ theme }) => theme.colors.background.surface};
  color: ${({ theme }) => theme.colors.text.heading};
  font: inherit;
  font-weight: 600;
  box-shadow: ${({ theme }) => theme.shadows.sm};
  cursor: pointer;

  &:hover {
    border-color: ${({ theme }) => theme.colors.primary.base};
    color: ${({ theme }) => theme.colors.primary.base};
  }
`;

const DividerHint = styled.p`
  margin: 0;
  font-size: 0.8rem;
  text-align: center;
  color: ${({ theme }) => theme.colors.text.muted};
`;
