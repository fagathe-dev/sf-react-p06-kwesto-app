import type { BooleanConfig } from '@/types';
import { styled } from 'styled-components';

interface BooleanControlProps {
  id: string;
  config: BooleanConfig;
  /** null : pas encore de réponse (boutons sans valeur par défaut). */
  value: boolean | null;
  onChange: (value: boolean) => void;
  /** Identifiant de l'intitulé de la question (groupe de boutons). */
  labelId?: string;
  describedBy?: string;
  invalid?: boolean;
  required?: boolean;
  disabled?: boolean;
}

/**
 * Réponse BOOLEAN (specs_fonc §4.6).
 * - `buttons` : deux boutons exclusifs (boutons radio natifs : flèches au clavier), pleine largeur sur mobile ;
 * - `switch` : interrupteur (case à cocher `role="switch"`), le libellé de la valeur courante est affiché à côté.
 */
export const BooleanControl = ({ id, config, value, onChange, labelId, describedBy, invalid = false, required = false, disabled = false }: BooleanControlProps) => {
  if (config.widget === 'switch') {
    const stateId = `${id}-state`;
    const checked = value === true;

    return (
      <SwitchLabel htmlFor={id} $disabled={disabled}>
        <SwitchInput
          id={id}
          type="checkbox"
          role="switch"
          checked={checked}
          disabled={disabled}
          aria-describedby={[stateId, describedBy].filter(Boolean).join(' ')}
          aria-invalid={invalid || undefined}
          onChange={(e) => onChange(e.target.checked)}
        />
        <Track aria-hidden="true" $checked={checked}>
          <Thumb $checked={checked} />
        </Track>
        <StateText id={stateId} $checked={checked}>
          {checked ? config.trueLabel : config.falseLabel}
        </StateText>
      </SwitchLabel>
    );
  }

  return (
    <Buttons role="radiogroup" aria-labelledby={labelId} aria-describedby={describedBy} aria-invalid={invalid || undefined} aria-required={required}>
      {([true, false] as const).map((option) => {
        const checked = value === option;
        return (
          <ButtonOption key={String(option)} $checked={checked} $disabled={disabled}>
            <input type="radio" name={id} value={String(option)} checked={checked} disabled={disabled} onChange={() => onChange(option)} />
            <span>{option ? config.trueLabel : config.falseLabel}</span>
          </ButtonOption>
        );
      })}
    </Buttons>
  );
};

const Buttons = styled.div`
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: ${({ theme }) => theme.spacing[2]};
  max-width: 420px;

  @media (max-width: 600px) {
    max-width: none;
  }
`;

const ButtonOption = styled.label<{ $checked: boolean; $disabled: boolean }>`
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 48px;
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[3]}`};
  border: 2px solid ${({ theme, $checked }) => ($checked ? theme.colors.primary.base : theme.colors.border.strong)};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme, $checked }) => ($checked ? theme.colors.primary.subtle : theme.colors.background.body)};
  color: ${({ theme, $checked }) => ($checked ? theme.colors.primary.dark : theme.colors.text.body)};
  font-weight: 600;
  text-align: center;
  cursor: ${({ $disabled }) => ($disabled ? 'default' : 'pointer')};
  transition:
    border-color 0.15s ease,
    background-color 0.15s ease;

  input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
  }

  &:focus-within {
    outline: 2px solid ${({ theme }) => theme.colors.primary.base};
    outline-offset: 2px;
  }

  &:hover {
    border-color: ${({ theme, $disabled }) => ($disabled ? undefined : theme.colors.primary.base)};
  }
`;

const SwitchLabel = styled.label<{ $disabled: boolean }>`
  position: relative;
  display: inline-flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
  min-height: 44px;
  cursor: ${({ $disabled }) => ($disabled ? 'default' : 'pointer')};
`;

const SwitchInput = styled.input`
  position: absolute;
  opacity: 0;
  width: 1px;
  height: 1px;

  &:focus-visible + span {
    outline: 2px solid ${({ theme }) => theme.colors.primary.base};
    outline-offset: 2px;
  }
`;

const Track = styled.span<{ $checked: boolean }>`
  position: relative;
  flex-shrink: 0;
  width: 52px;
  height: 30px;
  border-radius: ${({ theme }) => theme.radii.pill};
  background: ${({ theme, $checked }) => ($checked ? theme.colors.primary.base : theme.colors.border.strong)};
  transition: background-color 0.15s ease;
`;

const Thumb = styled.span<{ $checked: boolean }>`
  position: absolute;
  top: 3px;
  left: ${({ $checked }) => ($checked ? '25px' : '3px')};
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: ${({ theme }) => theme.colors.white};
  box-shadow: ${({ theme }) => theme.shadows.sm};
  transition: left 0.15s ease;

  @media (prefers-reduced-motion: reduce) {
    transition: none;
  }
`;

const StateText = styled.span<{ $checked: boolean }>`
  font-weight: 600;
  color: ${({ theme, $checked }) => ($checked ? theme.colors.primary.dark : theme.colors.text.body)};
`;
