import { appTheme } from '@/ui/theme';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ThemeProvider } from 'styled-components';
import { describe, expect, it, vi } from 'vitest';
import { InlineEditableText } from './InlineEditableText';

const setup = (props: Partial<Parameters<typeof InlineEditableText>[0]> = {}) => {
  const onSave = props.onSave ?? vi.fn().mockResolvedValue(undefined);
  render(
    <ThemeProvider theme={appTheme}>
      <InlineEditableText label="Titre du questionnaire" value="Baromètre 2026" onSave={onSave} {...props} />
    </ThemeProvider>
  );
  return { onSave, user: userEvent.setup() };
};

describe('InlineEditableText', () => {
  it('s\'affiche comme du texte et devient un champ au clic ; Entrée enregistre', async () => {
    const { onSave, user } = setup();

    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Titre du questionnaire : Baromètre 2026/ }));

    const field = screen.getByRole('textbox', { name: 'Titre du questionnaire' });
    expect(field).toHaveFocus();
    await user.clear(field);
    await user.type(field, '  Baromètre   2027 {Enter}');

    expect(onSave).toHaveBeenCalledWith('Baromètre 2027');
    expect(await screen.findByText('Enregistré')).toBeInTheDocument();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  });

  it('Échap annule sans enregistrer', async () => {
    const { onSave, user } = setup();

    await user.click(screen.getByRole('button'));
    await user.type(screen.getByRole('textbox'), ' modifié{Escape}');

    expect(onSave).not.toHaveBeenCalled();
    expect(screen.getByRole('button')).toHaveTextContent('Baromètre 2026');
  });

  it('garde le champ ouvert et affiche l\'erreur si la valeur est refusée', async () => {
    const onSave = vi.fn().mockRejectedValue(new Error('Le titre est déjà utilisé.'));
    const { user } = setup({ onSave, validate: (next) => (next.length < 3 ? 'Trop court.' : null) });

    await user.click(screen.getByRole('button'));
    const field = screen.getByRole('textbox');
    await user.clear(field);
    await user.type(field, 'ab{Enter}');
    expect(screen.getByRole('alert')).toHaveTextContent('Trop court.');
    expect(onSave).not.toHaveBeenCalled();

    await user.type(field, 'cdef{Enter}');
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Le titre est déjà utilisé.'));
    expect(screen.getByRole('textbox')).toHaveValue('abcdef');
  });

  it('affiche le texte d\'invite quand la valeur est vide, et rien d\'éditable en lecture seule', async () => {
    const { user } = setup({ value: '', multiline: true, emptyText: 'Ajouter une description…' });

    expect(screen.getByRole('button')).toHaveTextContent('Ajouter une description…');
    await user.click(screen.getByRole('button'));
    // Multiligne : Entrée ajoute une ligne, Ctrl+Entrée enregistre
    await user.type(screen.getByRole('textbox'), 'Ligne 1{Enter}Ligne 2');
    expect(screen.getByRole('textbox')).toHaveValue('Ligne 1\nLigne 2');
  });

  it('n\'est pas modifiable quand editable=false', () => {
    setup({ editable: false });

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText('Baromètre 2026')).toBeInTheDocument();
  });
});
