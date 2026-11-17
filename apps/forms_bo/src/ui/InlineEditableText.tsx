import { errorMessage } from '@/services/errors';
import { Icon } from 'core-react/icons';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { css, styled } from 'styled-components';

type Status = 'idle' | 'saving' | 'saved' | 'error';

interface InlineEditableTextProps {
  value: string;
  /** Nom accessible du champ, ex. « Titre du questionnaire ». */
  label: string;
  onSave: (next: string) => Promise<void>;
  editable?: boolean;
  multiline?: boolean;
  maxLength?: number;
  /** Message d'erreur si la valeur est refusée, null sinon. */
  validate?: (next: string) => string | null;
  /** Texte affiché quand la valeur est vide (mode lecture). */
  emptyText?: string;
  placeholder?: string;
  /** Typographie : titre de page ou texte courant atténué. */
  variant?: 'heading' | 'body';
}

/**
 * Texte modifiable en place : affiché comme du texte, il devient un champ au clic.
 * Entrée (Ctrl+Entrée en multiligne) ou perte du focus enregistre, Échap annule.
 */
export const InlineEditableText = ({
  value,
  label,
  onSave,
  editable = true,
  multiline = false,
  maxLength,
  validate,
  emptyText,
  placeholder,
  variant = 'body',
}: InlineEditableTextProps) => {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  const [status, setStatus] = useState<Status>('idle');
  const [error, setError] = useState<string | null>(null);
  const fieldRef = useRef<HTMLInputElement & HTMLTextAreaElement>(null);
  const savedTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const hintId = useId();

  useEffect(() => {
    if (!editing) return;
    const field = fieldRef.current;
    field?.focus();
    field?.setSelectionRange(field.value.length, field.value.length);
  }, [editing]);

  useEffect(() => () => {
    if (savedTimer.current) clearTimeout(savedTimer.current);
  }, []);

  const start = () => {
    setDraft(value);
    setError(null);
    setStatus('idle');
    setEditing(true);
  };

  const cancel = () => {
    setDraft(value);
    setError(null);
    setEditing(false);
  };

  const commit = async () => {
    if (status === 'saving') return;

    const next = multiline ? draft.trim() : draft.replace(/\s+/g, ' ').trim();
    if (next === value.trim()) {
      cancel();
      return;
    }

    const invalid = validate?.(next) ?? null;
    if (invalid) {
      setError(invalid);
      return;
    }

    setStatus('saving');
    try {
      await onSave(next);
      setEditing(false);
      setError(null);
      setStatus('saved');
      savedTimer.current = setTimeout(() => setStatus('idle'), 2000);
    } catch (err) {
      setStatus('error');
      setError(errorMessage(err, 'Enregistrement impossible.'));
    }
  };

  const onKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      cancel();
    } else if (event.key === 'Enter' && (!multiline || event.ctrlKey || event.metaKey)) {
      event.preventDefault();
      void commit();
    }
  };

  if (!editable) {
    return value ? <ReadOnly $variant={variant}>{value}</ReadOnly> : null;
  }

  if (!editing) {
    return (
      <Wrapper>
        <Display type="button" $variant={variant} $empty={value === ''} onClick={start} aria-label={`${label} : ${value || 'vide'}. Modifier`}>
          <span>{value || emptyText}</span>
          <Pencil aria-hidden="true">
            <Icon name="pencil-outline" size={variant === 'heading' ? 18 : 15} />
          </Pencil>
        </Display>
        <Status role="status" aria-live="polite">
          {status === 'saved' && (
            <>
              <Icon name="check" size={14} /> Enregistré
            </>
          )}
        </Status>
      </Wrapper>
    );
  }

  const fieldProps = {
    ref: fieldRef,
    value: draft,
    maxLength,
    placeholder,
    'aria-label': label,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': hintId,
    disabled: status === 'saving',
    onChange: (event: { target: { value: string } }) => {
      setDraft(event.target.value);
      if (error) setError(null);
    },
    onKeyDown,
    onBlur: () => void commit(),
  };

  return (
    <Wrapper>
      {multiline ? <Area {...fieldProps} $variant={variant} rows={3} /> : <Field {...fieldProps} $variant={variant} type="text" />}
      <Hint id={hintId} $error={Boolean(error)} role={error ? 'alert' : undefined}>
        {error ??
          (status === 'saving'
            ? 'Enregistrement…'
            : `${multiline ? 'Ctrl + Entrée' : 'Entrée'} pour enregistrer · Échap pour annuler${maxLength ? ` · ${draft.length}/${maxLength}` : ''}`)}
      </Hint>
    </Wrapper>
  );
};

const typography = {
  heading: css`
    font-size: 1.6rem;
    font-weight: 700;
    line-height: 1.25;
    color: ${({ theme }) => theme.colors.text.heading};
  `,
  body: css`
    font-size: 0.95rem;
    font-weight: 400;
    line-height: 1.55;
    color: ${({ theme }) => theme.colors.text.muted};
  `,
};

/** Même boîte en lecture et en édition : le texte ne saute pas au passage en champ. */
const box = css`
  width: 100%;
  margin: 0 -${({ theme }) => theme.spacing[2]};
  padding: ${({ theme }) => `${theme.spacing[1]} ${theme.spacing[2]}`};
  border: 1px solid transparent;
  border-radius: ${({ theme }) => theme.radii.md};
  font-family: inherit;
`;

// Éléments en ligne (span) : le composant peut se trouver dans un <h1>
const Wrapper = styled.span`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0 ${({ theme }) => theme.spacing[2]};
  max-width: 72ch;
`;

const ReadOnly = styled.span<{ $variant: 'heading' | 'body' }>`
  ${({ $variant }) => typography[$variant]}
  display: block;
  white-space: pre-line;
`;

const Pencil = styled.span`
  display: inline-flex;
  margin-left: ${({ theme }) => theme.spacing[2]};
  vertical-align: middle;
  color: ${({ theme }) => theme.colors.text.muted};
  opacity: 0;
  transition: opacity 0.15s ease;
`;

const Display = styled.button<{ $variant: 'heading' | 'body'; $empty: boolean }>`
  ${box}
  ${({ $variant }) => typography[$variant]}
  display: block;
  width: fit-content;
  max-width: calc(100% + ${({ theme }) => theme.spacing[4]});
  background: transparent;
  text-align: left;
  white-space: pre-line;
  cursor: text;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease;

  ${({ $empty }) =>
    $empty &&
    css`
      font-style: italic;
      opacity: 0.75;
    `}

  &:hover,
  &:focus-visible {
    background: ${({ theme }) => theme.colors.background.surfaceHover};
  }

  &:hover ${Pencil}, &:focus-visible ${Pencil} {
    opacity: 1;
  }

  @media (prefers-reduced-motion: reduce) {
    transition: none;
  }
`;

const fieldStyles = css<{ $variant: 'heading' | 'body' }>`
  ${box}
  ${({ $variant }) => typography[$variant]}
  display: block;
  flex: 0 0 calc(100% + ${({ theme }) => theme.spacing[4]});
  border-color: ${({ theme }) => theme.colors.primary.base};
  background: ${({ theme }) => theme.colors.background.surface};
  outline: 3px solid color-mix(in srgb, ${({ theme }) => theme.colors.primary.base} 18%, transparent);
  color: ${({ theme }) => theme.colors.text.body};

  &[aria-invalid='true'] {
    border-color: ${({ theme }) => theme.colors.danger.base};
    outline-color: color-mix(in srgb, ${({ theme }) => theme.colors.danger.base} 18%, transparent);
  }
`;

const Field = styled.input<{ $variant: 'heading' | 'body' }>`
  ${fieldStyles}
`;

const Area = styled.textarea<{ $variant: 'heading' | 'body' }>`
  ${fieldStyles}
  resize: none;
  /* Hauteur ajustée au contenu (navigateurs récents), 3 lignes sinon */
  field-sizing: content;
  min-height: 3lh;
`;

const Hint = styled.span<{ $error: boolean }>`
  display: block;
  flex-basis: 100%;
  margin-top: ${({ theme }) => theme.spacing[1]};
  font-size: 0.75rem;
  color: ${({ theme, $error }) => ($error ? theme.colors.danger.base : theme.colors.text.muted)};
`;

const Status = styled.span`
  display: inline-flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1]};
  font-size: 0.75rem;
  color: ${({ theme }) => theme.colors.success.base};
  white-space: nowrap;
`;
