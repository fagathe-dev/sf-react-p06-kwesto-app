import type { QuestionType } from '@/types';
import { appTheme } from '@/ui/theme';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { ThemeProvider } from 'styled-components';
import { describe, expect, it, vi } from 'vitest';
import { QuestionTypeSelect } from './QuestionTypeSelect';

const Harness = ({ onChange, initial = 'TEXT' }: { onChange: (type: QuestionType) => void; initial?: QuestionType }) => {
  const [value, setValue] = useState<QuestionType>(initial);
  return (
    <ThemeProvider theme={appTheme}>
      <QuestionTypeSelect
        value={value}
        onChange={(type) => {
          setValue(type);
          onChange(type);
        }}
      />
      <button type="button">Ailleurs</button>
    </ThemeProvider>
  );
};

describe('QuestionTypeSelect', () => {
  it('affiche le type courant et liste les 7 types avec leur description', async () => {
    const user = userEvent.setup();
    render(<Harness onChange={vi.fn()} />);

    const trigger = screen.getByRole('combobox', { name: 'Type Zone de texte' });
    expect(trigger).toHaveAttribute('aria-expanded', 'false');

    await user.click(trigger);
    const options = screen.getAllByRole('option');
    expect(options).toHaveLength(7);
    expect(screen.getByRole('option', { name: /Échelle/ })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: /Oui \/ Non/ })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: /Zone de texte/ })).toHaveAttribute('aria-selected', 'true');
    expect(screen.getByRole('option', { name: /Choix unique, multiple ou liste/ })).toBeInTheDocument();
  });

  it('sélectionne un type à la souris et referme la liste', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Harness onChange={onChange} />);

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: /Date/ }));

    expect(onChange).toHaveBeenCalledWith('DATE');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Type Date' })).toHaveFocus();
  });

  it('se pilote au clavier : flèches, Entrée, Échap, lettre', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Harness onChange={onChange} />);

    const trigger = screen.getByRole('combobox');
    trigger.focus();

    await user.keyboard('{ArrowDown}');
    expect(trigger).toHaveAttribute('aria-expanded', 'true');
    expect(trigger).toHaveAttribute('aria-activedescendant', screen.getByRole('option', { name: /Zone de texte/ }).id);

    await user.keyboard('{ArrowDown}{Enter}');
    expect(onChange).toHaveBeenLastCalledWith('CHOICE');

    await user.keyboard('{Enter}');
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(onChange).toHaveBeenCalledTimes(1);

    await user.keyboard('{Enter}f{Enter}');
    expect(onChange).toHaveBeenLastCalledWith('FILE');
  });

  it('se referme au clic en dehors sans changer le type', async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    render(<Harness onChange={onChange} />);

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('button', { name: 'Ailleurs' }));

    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    expect(onChange).not.toHaveBeenCalled();
  });
});
