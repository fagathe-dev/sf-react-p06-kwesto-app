import type { QuestionType } from '@/types';
import { Icon } from 'core-react/icons';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { css, styled } from 'styled-components';
import { QUESTION_TYPE_OPTIONS } from './questionTypeOptions';

interface QuestionTypeSelectProps {
  value: QuestionType;
  onChange: (type: QuestionType) => void;
  disabled?: boolean;
  label?: string;
}

/**
 * Liste déroulante du type de question, avec icône, libellé et description de chaque type.
 * Motif ARIA « combobox à sélection seule » : le focus reste sur le déclencheur, l'option active est
 * signalée par aria-activedescendant ; flèches, Début, Fin, Entrée / Espace, Échap, saisie de la première lettre.
 */
export const QuestionTypeSelect = ({ value, onChange, disabled = false, label = 'Type' }: QuestionTypeSelectProps) => {
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const id = useId();
  const labelId = `${id}-label`;
  const listId = `${id}-list`;
  const optionId = (index: number) => `${id}-option-${index}`;

  const selectedIndex = Math.max(0, QUESTION_TYPE_OPTIONS.findIndex((option) => option.type === value));
  const selected = QUESTION_TYPE_OPTIONS[selectedIndex]!;

  // Fermeture au clic en dehors
  useEffect(() => {
    if (!open) return undefined;
    const onPointerDown = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener('pointerdown', onPointerDown);
    return () => document.removeEventListener('pointerdown', onPointerDown);
  }, [open]);

  // L'option active reste visible dans la liste
  useEffect(() => {
    if (open) document.getElementById(`${id}-option-${active}`)?.scrollIntoView?.({ block: 'nearest' });
  }, [open, active, id]);

  const openList = () => {
    setActive(selectedIndex);
    setOpen(true);
  };

  const choose = (index: number) => {
    const option = QUESTION_TYPE_OPTIONS[index];
    setOpen(false);
    triggerRef.current?.focus();
    if (option && option.type !== value) onChange(option.type);
  };

  const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>) => {
    const last = QUESTION_TYPE_OPTIONS.length - 1;

    if (!open) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
        event.preventDefault();
        openList();
      }
      return;
    }

    const moves: Record<string, number> = {
      ArrowDown: Math.min(last, active + 1),
      ArrowUp: Math.max(0, active - 1),
      Home: 0,
      End: last,
      PageDown: last,
      PageUp: 0,
    };

    if (event.key in moves) {
      event.preventDefault();
      setActive(moves[event.key]!);
    } else if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      choose(active);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      setOpen(false);
    } else if (event.key === 'Tab') {
      setOpen(false);
    } else if (event.key.length === 1) {
      // Saisie au clavier : premier type dont le libellé commence par la lettre tapée
      const letter = event.key.toLowerCase();
      const match = QUESTION_TYPE_OPTIONS.findIndex((option, index) => index > active && option.label.toLowerCase().startsWith(letter));
      const fallback = QUESTION_TYPE_OPTIONS.findIndex((option) => option.label.toLowerCase().startsWith(letter));
      if (match !== -1 || fallback !== -1) setActive(match !== -1 ? match : fallback);
    }
  };

  return (
    <Root ref={rootRef}>
      <Label id={labelId}>{label}</Label>
      <Trigger
        ref={triggerRef}
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listId}
        aria-labelledby={`${labelId} ${id}-value`}
        aria-activedescendant={open ? optionId(active) : undefined}
        disabled={disabled}
        $open={open}
        onClick={() => (open ? setOpen(false) : openList())}
        onKeyDown={onKeyDown}
      >
        <TypeBadge aria-hidden="true">
          <Icon name={selected.icon} size={16} />
        </TypeBadge>
        <Value id={`${id}-value`}>{selected.label}</Value>
        {!disabled && (
          <Chevron $open={open} aria-hidden="true">
            <Icon name="chevron-down" size={18} />
          </Chevron>
        )}
      </Trigger>

      {open && (
        <List id={listId} role="listbox" aria-labelledby={labelId}>
          {QUESTION_TYPE_OPTIONS.map((option, index) => {
            const isSelected = option.type === value;
            return (
              <Option
                key={option.type}
                id={optionId(index)}
                role="option"
                aria-selected={isSelected}
                $active={index === active}
                $selected={isSelected}
                onPointerEnter={() => setActive(index)}
                // Évite la perte du focus du déclencheur avant le clic
                onPointerDown={(event) => event.preventDefault()}
                onClick={() => choose(index)}
              >
                <TypeBadge aria-hidden="true">
                  <Icon name={option.icon} size={16} />
                </TypeBadge>
                <OptionText>
                  <strong>{option.label}</strong>
                  <span>{option.description}</span>
                </OptionText>
                {isSelected && (
                  <Check aria-hidden="true">
                    <Icon name="check" size={18} />
                  </Check>
                )}
              </Option>
            );
          })}
        </List>
      )}
    </Root>
  );
};

const Root = styled.div`
  position: relative;
  min-width: 220px;
  margin-bottom: ${({ theme }) => theme.spacing[3]};
`;

const Label = styled.span`
  display: block;
  margin-bottom: ${({ theme }) => theme.spacing[1]};
  font-size: 0.875rem;
  font-weight: 600;
  color: ${({ theme }) => theme.colors.text.heading};
`;

const Trigger = styled.button<{ $open: boolean }>`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  width: 100%;
  min-height: 44px;
  padding: ${({ theme }) => `${theme.spacing[1.5]} ${theme.spacing[3]} ${theme.spacing[1.5]} ${theme.spacing[1.5]}`};
  border: 1px solid ${({ theme, $open }) => ($open ? theme.colors.primary.base : theme.colors.border.default)};
  border-radius: ${({ theme }) => theme.radii.base};
  background: ${({ theme }) => theme.colors.background.surface};
  color: ${({ theme }) => theme.colors.text.body};
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;

  ${({ $open, theme }) =>
    $open &&
    css`
      box-shadow: 0 0 0 3px ${theme.borders.focusRing.color};
    `}

  &:hover:not(:disabled) {
    border-color: ${({ theme }) => theme.colors.border.hover};
  }

  &:focus-visible {
    outline: none;
    border-color: ${({ theme }) => theme.colors.primary.base};
    box-shadow: 0 0 0 3px ${({ theme }) => theme.borders.focusRing.color};
  }

  &:disabled {
    cursor: default;
    background: ${({ theme }) => theme.colors.background.surfaceHover};
  }
`;

const TypeBadge = styled.span`
  display: inline-grid;
  flex-shrink: 0;
  place-items: center;
  width: 30px;
  height: 30px;
  border-radius: ${({ theme }) => theme.radii.sm};
  background: ${({ theme }) => theme.colors.primary.subtle};
  color: ${({ theme }) => theme.colors.primary.base};
`;

const Value = styled.span`
  flex: 1;
  font-weight: 500;
`;

const Chevron = styled.span<{ $open: boolean }>`
  display: inline-flex;
  color: ${({ theme }) => theme.colors.text.muted};
  transform: rotate(${({ $open }) => ($open ? '180deg' : '0deg')});
  transition: transform 0.15s ease;

  @media (prefers-reduced-motion: reduce) {
    transition: none;
  }
`;

const List = styled.ul`
  position: absolute;
  top: calc(100% + ${({ theme }) => theme.spacing[1]});
  left: 0;
  z-index: 30;
  width: max(100%, 300px);
  max-height: 340px;
  margin: 0;
  padding: ${({ theme }) => theme.spacing[1]};
  overflow-y: auto;
  list-style: none;
  border: 1px solid ${({ theme }) => theme.colors.border.default};
  border-radius: ${({ theme }) => theme.radii.lg};
  background: ${({ theme }) => theme.colors.background.surface};
  box-shadow: ${({ theme }) => theme.shadows.lg};
`;

const Option = styled.li<{ $active: boolean; $selected: boolean }>`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
  padding: ${({ theme }) => theme.spacing[2]};
  border-radius: ${({ theme }) => theme.radii.md};
  cursor: pointer;
  background: ${({ theme, $active, $selected }) =>
    $active ? theme.colors.background.surfaceHover : $selected ? `color-mix(in srgb, ${theme.colors.primary.subtle} 60%, transparent)` : 'transparent'};

  ${({ $active, theme }) =>
    $active &&
    css`
      box-shadow: inset 0 0 0 1px ${theme.colors.border.default};
    `}
`;

const OptionText = styled.span`
  flex: 1;
  min-width: 0;

  strong {
    display: block;
    font-weight: 600;
    color: ${({ theme }) => theme.colors.text.heading};
  }

  span {
    display: block;
    font-size: 0.8rem;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;

const Check = styled.span`
  display: inline-flex;
  color: ${({ theme }) => theme.colors.primary.base};
`;
