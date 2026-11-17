import type { ChoiceOption } from '@/types';
import { Icon } from 'core-react/icons';
import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { css, styled } from 'styled-components';
import { matches } from './choiceSearch';

interface ChoiceAutocompleteProps {
  id: string;
  options: ChoiceOption[];
  /** Identifiant de l'option choisie, ou null. */
  value: string | null;
  onChange: (optionId: string | null) => void;
  describedBy?: string;
  invalid?: boolean;
  required?: boolean;
}

/**
 * Liste déroulante à saisie semi-automatique (motif ARIA « combobox » avec liste) : on tape quelques lettres,
 * la liste se filtre, flèches + Entrée pour choisir, Échap pour refermer. Remplace le `<select>` natif dès
 * CHOICE_SEARCH_THRESHOLD options (choiceSearch.ts).
 */
export const ChoiceAutocomplete = ({ id, options, value, onChange, describedBy, invalid = false, required = false }: ChoiceAutocompleteProps) => {
  const selected = options.find((o) => o.id === value) ?? null;
  const [query, setQuery] = useState(selected?.label ?? '');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);
  const listId = `${id}-listbox`;
  const optionId = (index: number) => `${id}-option-${index}`;

  // Tant que le texte est celui de l'option choisie, toute la liste est proposée
  const filtered = useMemo(() => (selected && query === selected.label ? options : options.filter((o) => matches(o.label, query))), [options, query, selected]);

  /** Fermeture : le texte revient au libellé choisi (une saisie partielle ne vaut pas réponse). */
  const close = () => {
    setOpen(false);
    setQuery(selected?.label ?? '');
  };

  // Clic en dehors : fermeture
  useEffect(() => {
    if (!open) return undefined;
    const onPointerDown = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) close();
    };
    document.addEventListener('pointerdown', onPointerDown);
    return () => document.removeEventListener('pointerdown', onPointerDown);
  });

  useEffect(() => {
    if (open) document.getElementById(`${id}-option-${active}`)?.scrollIntoView?.({ block: 'nearest' });
  }, [open, active, id]);

  const choose = (option: ChoiceOption) => {
    onChange(option.id);
    setQuery(option.label);
    setOpen(false);
  };

  const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    const last = filtered.length - 1;
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        if (!open) {
          setOpen(true);
          setActive(Math.max(0, filtered.findIndex((o) => o.id === value)));
        } else setActive(Math.min(last, active + 1));
        break;
      case 'ArrowUp':
        event.preventDefault();
        if (open) setActive(Math.max(0, active - 1));
        break;
      case 'Enter':
        if (open && filtered[active]) {
          event.preventDefault();
          choose(filtered[active]);
        }
        break;
      case 'Escape':
        if (open) {
          event.preventDefault();
          event.stopPropagation();
          close();
        }
        break;
      case 'Tab':
        if (open) close();
        break;
    }
  };

  return (
    <Root ref={rootRef}>
      <InputBox $open={open} $invalid={invalid}>
        <Icon name="magnify" size={18} aria-hidden="true" />
        <input
          id={id}
          type="text"
          role="combobox"
          autoComplete="off"
          aria-autocomplete="list"
          aria-expanded={open}
          aria-controls={listId}
          aria-activedescendant={open && filtered[active] ? optionId(active) : undefined}
          aria-describedby={describedBy}
          aria-invalid={invalid || undefined}
          aria-required={required}
          placeholder={`Rechercher parmi ${options.length} réponses…`}
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setActive(0);
            setOpen(true);
            if (e.target.value.trim() === '' && value) onChange(null);
          }}
          onFocus={() => setOpen(true)}
          onClick={() => setOpen(true)}
          onKeyDown={onKeyDown}
        />
        {value && (
          <ClearButton
            type="button"
            aria-label="Effacer la réponse"
            onClick={() => {
              onChange(null);
              setQuery('');
              setOpen(true);
            }}
          >
            <Icon name="close" size={16} />
          </ClearButton>
        )}
      </InputBox>

      {open && (
        <List id={listId} role="listbox" aria-label="Réponses proposées">
          {filtered.length === 0 && <Empty role="presentation">Aucune réponse ne correspond à « {query} ».</Empty>}
          {filtered.map((option, index) => (
            <Option
              key={option.id}
              id={optionId(index)}
              role="option"
              aria-selected={option.id === value}
              $active={index === active}
              onPointerEnter={() => setActive(index)}
              onPointerDown={(e) => e.preventDefault()}
              onClick={() => choose(option)}
            >
              {option.label}
              {option.id === value && <Icon name="check" size={16} aria-hidden="true" />}
            </Option>
          ))}
        </List>
      )}
    </Root>
  );
};

interface ChoiceSearchProps {
  id: string;
  total: number;
  shown: number;
  query: string;
  onQuery: (query: string) => void;
}

/** Recherche au-dessus d'une liste de boutons radio ou de cases à cocher. */
export const ChoiceSearch = ({ id, total, shown, query, onQuery }: ChoiceSearchProps) => (
  <SearchBox>
    <InputBox $open={false} $invalid={false}>
      <Icon name="magnify" size={18} aria-hidden="true" />
      <input id={id} type="search" aria-label="Filtrer les réponses" placeholder={`Rechercher parmi ${total} réponses…`} value={query} onChange={(e) => onQuery(e.target.value)} />
    </InputBox>
    <Count aria-live="polite">{query.trim() === '' ? '' : `${shown} réponse${shown > 1 ? 's' : ''} sur ${total}`}</Count>
  </SearchBox>
);

const Root = styled.div`
  position: relative;
`;

const InputBox = styled.div<{ $open: boolean; $invalid: boolean }>`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  padding: 0 ${({ theme }) => theme.spacing[3]};
  border: 1px solid ${({ theme, $invalid }) => ($invalid ? theme.colors.danger.base : theme.colors.border.strong)};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.body};
  color: ${({ theme }) => theme.colors.text.muted};

  ${({ $open, theme }) =>
    $open &&
    css`
      border-color: ${theme.colors.primary.base};
    `}

  &:focus-within {
    outline: 2px solid ${({ theme }) => theme.colors.primary.base};
    outline-offset: 1px;
  }

  input {
    flex: 1;
    min-width: 0;
    padding: ${({ theme }) => theme.spacing[3]} 0;
    border: 0;
    background: transparent;
    color: ${({ theme }) => theme.colors.text.body};
    font: inherit;
    font-size: 1rem;
    outline: none;
  }
`;

const ClearButton = styled.button`
  display: inline-grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border: 0;
  border-radius: 50%;
  background: transparent;
  color: ${({ theme }) => theme.colors.text.muted};
  cursor: pointer;

  &:hover,
  &:focus-visible {
    background: ${({ theme }) => theme.colors.background.surfaceHover};
  }
`;

const List = styled.ul`
  position: absolute;
  top: calc(100% + ${({ theme }) => theme.spacing[1]});
  right: 0;
  left: 0;
  z-index: 20;
  max-height: 280px;
  margin: 0;
  padding: ${({ theme }) => theme.spacing[1]};
  overflow-y: auto;
  list-style: none;
  border: 1px solid ${({ theme }) => theme.colors.border.default};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.surface};
  box-shadow: ${({ theme }) => theme.shadows.lg};
`;

const Option = styled.li<{ $active: boolean }>`
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: ${({ theme }) => theme.spacing[2]};
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[3]}`};
  border-radius: ${({ theme }) => theme.radii.sm};
  cursor: pointer;
  background: ${({ theme, $active }) => ($active ? theme.colors.background.surfaceHover : 'transparent')};

  &[aria-selected='true'] {
    font-weight: 600;
    color: ${({ theme }) => theme.colors.primary.dark};
  }
`;

const Empty = styled.li`
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[3]}`};
  color: ${({ theme }) => theme.colors.text.muted};
  font-size: 0.9rem;
`;

const SearchBox = styled.div`
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[1]};
`;

const Count = styled.span`
  min-height: 1em;
  font-size: 0.8rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;
