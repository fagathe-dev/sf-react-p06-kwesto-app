import { HelperText, Label } from 'core-react/ui';
import { useId, type InputHTMLAttributes } from 'react';
import { styled } from 'styled-components';

interface DateTimeFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label: string;
  error?: string;
  hint?: string;
}

/** Champ date + heure : `TextField` de core-react ne propose pas le type datetime-local. */
export const DateTimeField = ({ label, error, hint, id, required, ...props }: DateTimeFieldProps) => {
  const generated = useId();
  const inputId = id ?? generated;
  const helpId = `${inputId}-help`;

  return (
    <Wrapper>
      <Label htmlFor={inputId} $required={required}>
        {label}
      </Label>
      <Input id={inputId} type="datetime-local" required={required} aria-invalid={error ? true : undefined} aria-describedby={error || hint ? helpId : undefined} {...props} />
      {(error || hint) && (
        <HelperText id={helpId} $state={error ? 'invalid' : 'default'}>
          {error ?? hint}
        </HelperText>
      )}
    </Wrapper>
  );
};

const Wrapper = styled.div`
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[1]};
  margin-bottom: ${({ theme }) => theme.spacing[3]};
`;

const Input = styled.input`
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[3]}`};
  border: 1px solid ${({ theme }) => theme.colors.border.default};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.body};
  color: ${({ theme }) => theme.colors.text.body};
  font: inherit;

  &:focus {
    outline: 2px solid ${({ theme }) => theme.colors.primary.base};
    outline-offset: 1px;
  }

  &[aria-invalid='true'] {
    border-color: ${({ theme }) => theme.colors.danger.base};
  }
`;
