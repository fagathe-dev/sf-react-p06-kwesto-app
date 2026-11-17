import type { Survey, SurveyResponse } from '@/types';
import { appTheme } from '@/ui/theme';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from 'styled-components';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AddQuestionButton } from './AddQuestion';
import { SurveyBuilderPage } from './SurveyBuilderPage';

const api = vi.hoisted(() => ({
  fetchSurvey: vi.fn(),
  saveStructure: vi.fn(),
  updateSurvey: vi.fn(),
  validateSurvey: vi.fn(),
  fetchPlatformConfig: vi.fn(),
}));

vi.mock('@/services/api', () => api);

const withTheme = (node: ReactNode) => <ThemeProvider theme={appTheme}>{node}</ThemeProvider>;

const survey = (questions: Survey['questions']): SurveyResponse => ({
  survey: {
    id: 'survey-1',
    title: 'Baromètre',
    description: null,
    status: 'DRAFT',
    expiresAt: null,
    activatedAt: null,
    closedAt: null,
    closeReason: null,
    createdAt: '2026-11-16T10:00:00+01:00',
    updatedAt: '2026-11-16T10:00:00+01:00',
    questionsCount: questions.length,
    validatedAt: null,
    reminderSentAt: null,
    remindersCount: 0,
    displayMode: 'LIST',
    showDuration: true,
    stepper: false,
    sections: [],
    questions,
  },
  recipients: { total: 0, valid: 0, pending: 0, sent: 0, opened: 0, answered: 0, bounced: 0 },
});

const textQuestion = (id: string, label: string, position: number): Survey['questions'][number] => ({
  id,
  sectionId: null,
  type: 'TEXT',
  label,
  helpTitle: null,
  helpText: null,
  required: true,
  position,
  config: { multiline: false, maxLength: 255 },
});

const renderBuilder = () =>
  render(
    withTheme(
      <MemoryRouter initialEntries={['/surveys/survey-1']}>
        <Routes>
          <Route path="/surveys/:id" element={<SurveyBuilderPage />} />
        </Routes>
      </MemoryRouter>
    )
  );

describe('AddQuestionButton', () => {
  it('se déploie en choix de type, se referme avec Échap et rend le focus au bouton', async () => {
    const user = userEvent.setup();
    const onAdd = vi.fn();
    render(withTheme(<AddQuestionButton onAdd={onAdd} />));

    const trigger = screen.getByRole('button', { name: 'Ajouter une question' });
    await user.click(trigger);

    const picker = screen.getByRole('group', { name: 'Quel type de question ?' });
    expect(within(picker).getAllByRole('button', { name: /Zone de texte|Choix|Oui \/ Non|Échelle|Chiffres|Date|Fichier/ })).toHaveLength(7);
    expect(within(picker).getByRole('button', { name: /Zone de texte/ })).toHaveFocus();

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('group')).not.toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Ajouter une question' })).toHaveFocus());

    await user.click(screen.getByRole('button', { name: 'Ajouter une question' }));
    await user.click(screen.getByRole('button', { name: /Choix/ }));
    expect(onAdd).toHaveBeenCalledWith('CHOICE');
  });

  it('affiche directement le choix du type dans une section vide', () => {
    render(withTheme(<AddQuestionButton empty onAdd={vi.fn()} />));

    expect(screen.getByRole('group', { name: 'Ajoutez une première question' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Fermer le choix du type' })).not.toBeInTheDocument();
  });
});

describe('Builder : ajout de questions', () => {
  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    api.fetchPlatformConfig.mockResolvedValue({ uploadMaxSizeMb: 10, fileFormats: [] });
    api.saveStructure.mockImplementation((_id: string, payload: { questions: Survey['questions'] }) =>
      Promise.resolve(survey(payload.questions.map((q, i) => ({ ...q, position: i + 1 }) as Survey['questions'][number])))
    );
  });

  it('questionnaire vide : choix du type affiché d\'emblée, la question créée reçoit le focus', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([]));
    renderBuilder();

    const picker = await screen.findByRole('group', { name: 'Ajoutez une première question' });
    await user.click(within(picker).getByRole('button', { name: /Date/ }));

    expect(screen.getByRole('article', { name: 'Question 1' })).toBeInTheDocument();
    expect(screen.getByLabelText(/Intitulé de la question/)).toHaveFocus();
    expect(screen.getByRole('button', { name: 'Ajouter une question' })).toBeInTheDocument();
  });

  it('insère une question entre deux autres et renumérote', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([textQuestion('a', 'Première', 1), textQuestion('b', 'Seconde', 2)]));
    renderBuilder();

    await user.click(await screen.findByRole('button', { name: 'Insérer une question après la question 1' }));
    await user.click(within(screen.getByRole('group', { name: /Insérer une question après la question 1/ })).getByRole('button', { name: /Chiffres/ }));

    const cards = screen.getAllByRole('article');
    expect(cards.map((card) => card.getAttribute('aria-label'))).toEqual(['Question 1', 'Question 2', 'Question 3']);
    expect(within(cards[1]!).getByRole('combobox', { name: 'Type Chiffres' })).toBeInTheDocument();
    expect(within(cards[1]!).getByLabelText(/Intitulé de la question/)).toHaveFocus();

    // Accordéon : seule la question insérée est dépliée, les autres se résument à leur intitulé
    expect(within(cards[2]!).getByText('Seconde')).toBeInTheDocument();
    expect(within(cards[2]!).queryByLabelText(/Intitulé de la question/)).not.toBeInTheDocument();
    expect(screen.getAllByLabelText(/Intitulé de la question/)).toHaveLength(1);
  });

  it('question Oui / Non : interrupteur avec « Non » par défaut, aperçu à jour', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([]));
    renderBuilder();

    const picker = await screen.findByRole('group', { name: 'Ajoutez une première question' });
    await user.click(within(picker).getByRole('button', { name: /Oui \/ Non/ }));

    const card = screen.getByRole('article', { name: 'Question 1' });
    expect(within(card).getByRole('combobox', { name: 'Type Oui / Non' })).toBeInTheDocument();
    expect(within(card).getByLabelText('Réponse « vrai »')).toHaveValue('Oui');
    expect(within(card).getByLabelText('Réponse « faux »')).toHaveValue('Non');

    // Par défaut : deux boutons, aucune valeur présélectionnée
    expect(within(card).getByRole('radio', { name: 'Aucune' })).toHaveAttribute('aria-checked', 'true');

    // Interrupteur : une valeur par défaut est imposée (« Non »), « Aucune » disparaît
    await user.click(within(card).getByRole('radio', { name: 'Interrupteur' }));
    expect(within(card).queryByRole('radio', { name: 'Aucune' })).not.toBeInTheDocument();
    const defaults = within(card).getByRole('radiogroup', { name: 'Valeur par défaut' });
    expect(within(defaults).getByRole('radio', { name: 'Non' })).toHaveAttribute('aria-checked', 'true');

    const preview = within(card).getByRole('switch');
    expect(preview).not.toBeChecked();
    await user.click(within(defaults).getByRole('radio', { name: 'Oui' }));
    expect(within(card).getByRole('switch')).toBeChecked();

    // Libellés personnalisés → preset « Personnalisé »
    await user.clear(within(card).getByLabelText('Réponse « vrai »'));
    await user.type(within(card).getByLabelText('Réponse « vrai »'), 'Intéressé');
    expect(within(card).getByRole('radio', { name: 'Personnalisé' })).toHaveAttribute('aria-checked', 'true');
  });

  it('condition d\'affichage : « Nom de jeune fille » si Civilité = Madame, retirée quand la source est supprimée', async () => {
    const user = userEvent.setup();
    const civility: Survey['questions'][number] = {
      ...textQuestion('civ', 'Civilité', 1),
      type: 'CHOICE',
      config: { widget: 'radio', options: [{ id: 'mr', label: 'Monsieur' }, { id: 'mme', label: 'Madame' }] },
    };
    api.fetchSurvey.mockResolvedValue(survey([civility, textQuestion('maiden', 'Nom de jeune fille', 2)]));
    renderBuilder();

    // La première question ne peut pas être conditionnelle
    await user.click(await screen.findByRole('button', { name: /Civilité/ }));
    expect(screen.getByText(/La première question est toujours affichée/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /Nom de jeune fille/ }));
    const card = screen.getByRole('article', { name: 'Question 2' });
    await user.click(within(card).getByRole('checkbox', { name: /Afficher cette question uniquement si/ }));

    expect(within(card).getByLabelText('Question')).toHaveValue('civ');
    expect(within(card).getByLabelText('Condition')).toHaveValue('EQUALS');
    await user.selectOptions(within(card).getByLabelText('Réponse'), 'mme');
    expect(within(card).getAllByText(/Si Q1 est « Madame »/).length).toBeGreaterThan(0);

    // Enregistrement : la condition part avec la structure
    await waitFor(() => expect(api.saveStructure).toHaveBeenCalled(), { timeout: 2000 });
    const payload = api.saveStructure.mock.calls.at(-1)![1] as { questions: Array<{ id: string; displayCondition: unknown }> };
    expect(payload.questions.find((q) => q.id === 'maiden')?.displayCondition).toEqual({ sourceQuestionId: 'civ', operator: 'EQUALS', value: 'mme' });

    // Suppression de la source : la confirmation le signale, la condition est retirée
    await user.click(screen.getByRole('button', { name: 'Supprimer la question 1' }));
    expect(screen.getByText(/1 question\(s\) en dépendent/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Supprimer' }));
    expect(screen.queryByText(/Si Q1 est/)).not.toBeInTheDocument();
  });

  it('options du questionnaire : la durée estimée se masque, le nombre de questions reste affiché', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([textQuestion('a', 'Première', 1), textQuestion('b', 'Seconde', 2)]));
    api.updateSurvey.mockImplementation((_id: string, data: { showDuration?: boolean }) => Promise.resolve({ ...survey([textQuestion('a', 'Première', 1), textQuestion('b', 'Seconde', 2)]), survey: { ...survey([]).survey, ...data } }));
    renderBuilder();

    const options = await screen.findByRole('region', { name: 'Options du questionnaire' });
    expect(within(options).getByText(/Le participant verra : « Durée estimée : .* · 2 questions »/)).toBeInTheDocument();

    await user.click(within(options).getByRole('checkbox', { name: 'Afficher la durée estimée' }));
    expect(api.updateSurvey).toHaveBeenCalledWith('survey-1', { showDuration: false });
  });

  it('condition d\'affichage : seules les questions Oui / Non et à choix unique sont proposées comme source', async () => {
    const user = userEvent.setup();
    const options = [{ id: 'a', label: 'A' }, { id: 'b', label: 'B' }];
    api.fetchSurvey.mockResolvedValue(
      survey([
        textQuestion('first', 'Prénom', 1),
        { ...textQuestion('hobbies', 'Loisirs', 2), type: 'CHOICE', config: { widget: 'checkbox', options } },
        { ...textQuestion('adult', 'Majeur', 3), type: 'BOOLEAN', config: { preset: 'YES_NO', trueLabel: 'Oui', falseLabel: 'Non', widget: 'buttons', defaultValue: null } },
        { ...textQuestion('city', 'Ville', 4), type: 'CHOICE', config: { widget: 'select', options } },
        textQuestion('last', 'Commentaire', 5),
      ])
    );
    renderBuilder();

    // Question 2 : la seule question précédente (texte) ne peut pas servir de condition
    await user.click(await screen.findByRole('button', { name: /Loisirs/ }));
    expect(screen.getByText(/seules les questions « Oui \/ Non », à choix unique/)).toBeInTheDocument();

    // Question 5 : Majeur (Oui / Non) et Ville (liste) seulement, pas Prénom (texte) ni Loisirs (choix multiple)
    await user.click(screen.getByRole('button', { name: /Commentaire/ }));
    const card = screen.getByRole('article', { name: 'Question 5' });
    await user.click(within(card).getByRole('checkbox', { name: /Afficher cette question uniquement si/ }));
    const sources = within(within(card).getByLabelText('Question')).getAllByRole('option').map((o) => o.textContent);
    expect(sources).toEqual(['Q3. Majeur', 'Q4. Ville']);
    expect(within(card).getByLabelText('Condition')).toHaveValue('EQUALS');
    expect(within(within(card).getByLabelText('Condition')).getAllByRole('option').map((o) => o.textContent)).toEqual(['est', 'n\'est pas']);
  });

  it('condition sur une note : « Comment améliorer ? » si la note de Q1 est inférieure à 5', async () => {
    const user = userEvent.setup();
    const note: Survey['questions'][number] = {
      ...textQuestion('note', 'Votre note', 1),
      type: 'RANGE',
      config: { min: 1, max: 10, step: 1, unit: '/10', defaultValue: null },
    };
    api.fetchSurvey.mockResolvedValue(survey([note, textQuestion('improve', 'Comment pourrait-on améliorer l\'expérience ?', 2)]));
    renderBuilder();

    await user.click(await screen.findByRole('button', { name: /Comment pourrait-on améliorer/ }));
    const card = screen.getByRole('article', { name: 'Question 2' });
    await user.click(within(card).getByRole('checkbox', { name: /Afficher cette question uniquement si/ }));

    expect(within(card).getByLabelText('Question')).toHaveValue('note');
    const operators = within(within(card).getByLabelText('Condition')).getAllByRole('option').map((o) => o.textContent);
    expect(operators).toEqual(['est égal à', 'est différent de', 'est inférieur à', 'est inférieur ou égal à', 'est supérieur à', 'est supérieur ou égal à']);

    await user.selectOptions(within(card).getByLabelText('Condition'), 'LESS_THAN');
    await user.selectOptions(within(card).getByLabelText('Valeur'), '5');
    expect(within(card).getAllByText(/Si Q1 est inférieur à « 5\/10 »/).length).toBeGreaterThan(0);

    await waitFor(() => expect(api.saveStructure).toHaveBeenCalled(), { timeout: 2000 });
    const payload = api.saveStructure.mock.calls.at(-1)![1] as { questions: Array<{ id: string; displayCondition: unknown }> };
    expect(payload.questions.find((q) => q.id === 'improve')?.displayCondition).toEqual({ sourceQuestionId: 'note', operator: 'LESS_THAN', value: 5 });
  });

  it('question Échelle : 0 à 10 par défaut, libellés, valeur de départ facultative, aperçu du curseur', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([]));
    renderBuilder();

    const picker = await screen.findByRole('group', { name: 'Ajoutez une première question' });
    await user.click(within(picker).getByRole('button', { name: /Échelle/ }));

    const card = screen.getByRole('article', { name: 'Question 1' });
    expect(within(card).getByRole('combobox', { name: 'Type Échelle' })).toBeInTheDocument();
    expect(within(card).getByLabelText('Minimum')).toHaveValue('0');
    expect(within(card).getByLabelText('Maximum')).toHaveValue('10');
    expect(within(card).getByLabelText('Suffixe')).toHaveValue('/10');
    expect(within(card).getByLabelText('Libellé à gauche (minimum)')).toHaveValue('Pas du tout');

    // Aperçu : sans valeur de départ, le curseur attend une réponse
    const preview = within(card).getByRole('slider', { name: 'Aperçu du curseur' });
    expect(preview).toHaveAttribute('aria-valuetext', 'Déplacez le curseur');

    // Valeur de départ : le curseur démarre sur le minimum
    await user.click(within(card).getByRole('checkbox', { name: 'Valeur de départ' }));
    expect(within(card).getByText('Le curseur démarre sur « 0/10 ».')).toBeInTheDocument();
    expect(within(card).getByRole('slider', { name: 'Aperçu du curseur' })).toHaveAttribute('aria-valuetext', '0/10');

    // Échelle incohérente : signalée, sans aperçu (le champ peut être vidé puis ressaisi)
    await user.clear(within(card).getByLabelText('Maximum'));
    expect(within(card).getByLabelText('Maximum')).toHaveValue('');
    await user.type(within(card).getByLabelText('Maximum'), '-5');
    expect(within(card).getByText('La valeur minimale doit être inférieure à la valeur maximale.')).toBeInTheDocument();
    expect(within(card).queryByRole('slider')).not.toBeInTheDocument();
  });

  it('question Échelle : valeurs personnalisées (2, 4, 6, 22, 40, 79)', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([]));
    renderBuilder();

    const picker = await screen.findByRole('group', { name: 'Ajoutez une première question' });
    await user.click(within(picker).getByRole('button', { name: /Échelle/ }));
    const card = screen.getByRole('article', { name: 'Question 1' });

    // Passage en valeurs personnalisées : l'intervalle 0 → 10 est repris comme point de départ
    await user.click(within(card).getByRole('radio', { name: 'Valeurs personnalisées' }));
    const values = within(card).getByLabelText('Valeurs de l\'échelle');
    expect(values).toHaveValue('0; 1; 2; 3; 4; 5; 6; 7; 8; 9; 10');
    expect(within(card).queryByLabelText('Pas')).not.toBeInTheDocument();

    await user.clear(values);
    await user.type(values, '79; 2; 4; 6; 22; 40');
    await user.clear(within(card).getByLabelText('Suffixe'));
    expect(within(card).getByText(/6 valeurs, dans l'ordre : 2 · 4 · 6 · 22 · 40 · 79/)).toBeInTheDocument();

    // Le curseur avance de valeur en valeur et affiche la valeur réelle
    const preview = within(card).getByRole('slider', { name: 'Aperçu du curseur' });
    expect(preview).toHaveAttribute('max', '5');
    // Sans réponse, le curseur est au milieu (cran 3) : on le déplace au cran 4, soit la valeur 40
    fireEvent.change(preview, { target: { value: '4' } });
    expect(within(card).getByRole('slider', { name: 'Aperçu du curseur' })).toHaveAttribute('aria-valuetext', '40');

    // Enregistrement : liste triée, min et max déduits
    await waitFor(() => expect(api.saveStructure).toHaveBeenCalled(), { timeout: 2000 });
    const payload = api.saveStructure.mock.calls.at(-1)![1] as { questions: Array<{ config: Record<string, unknown> }> };
    expect(payload.questions[0]!.config).toMatchObject({ values: [2, 4, 6, 22, 40, 79], min: 2, max: 79 });
  });

  it('accordéon : déplier une question replie la précédente', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([textQuestion('a', 'Première', 1), textQuestion('b', 'Seconde', 2)]));
    renderBuilder();

    const first = await screen.findByRole('button', { name: /Première/ });
    const second = screen.getByRole('button', { name: /Seconde/ });
    expect(first).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByLabelText(/Intitulé de la question/)).not.toBeInTheDocument();

    await user.click(first);
    expect(first).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByLabelText(/Intitulé de la question/)).toHaveValue('Première');

    await user.click(second);
    expect(first).toHaveAttribute('aria-expanded', 'false');
    expect(second).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByLabelText(/Intitulé de la question/)).toHaveValue('Seconde');

    await user.click(second);
    expect(screen.queryByLabelText(/Intitulé de la question/)).not.toBeInTheDocument();
  });

  it('aperçu : navigation question par question sans rien enregistrer', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([textQuestion('a', 'Première', 1), textQuestion('b', 'Seconde', 2)]));
    renderBuilder();

    await user.click(await screen.findByRole('button', { name: /Aperçu/ }));
    const dialog = screen.getByRole('dialog', { name: /Aperçu/ });

    // Le questionnaire est en liste : un seul écran ; on bascule l'aperçu en question par question
    expect(within(dialog).getByText('0 question renseignée sur 2')).toBeInTheDocument();
    expect(within(dialog).queryByRole('button', { name: /Suivant/ })).not.toBeInTheDocument();
    await user.click(within(dialog).getByRole('radio', { name: /Question par question/ }));
    expect(within(dialog).getByText('Question 1 sur 2')).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/Première/)).toBeInTheDocument();

    // Question obligatoire laissée vide : on avance quand même, les contrôles sont désactivés par défaut
    await user.click(within(dialog).getByRole('button', { name: /Suivant/ }));
    expect(within(dialog).getByText('Question 2 sur 2')).toBeInTheDocument();
    expect(within(dialog).getByLabelText(/Seconde/)).toBeInTheDocument();

    await user.click(within(dialog).getByRole('button', { name: /Précédent/ }));
    expect(within(dialog).getByLabelText(/Première/)).toBeInTheDocument();

    await user.click(within(dialog).getByRole('button', { name: /Suivant/ }));
    await user.click(within(dialog).getByRole('button', { name: /Terminer l'aperçu/ }));
    expect(within(dialog).getByText('Fin de l\'aperçu')).toBeInTheDocument();

    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(api.saveStructure).not.toHaveBeenCalled();
  });

  it('aperçu : les contrôles de saisie bloquent « Suivant » quand on les active', async () => {
    const user = userEvent.setup();
    api.fetchSurvey.mockResolvedValue(survey([textQuestion('a', 'Première', 1), textQuestion('b', 'Seconde', 2)]));
    renderBuilder();

    await user.click(await screen.findByRole('button', { name: /Aperçu/ }));
    const dialog = screen.getByRole('dialog');
    await user.click(within(dialog).getByRole('radio', { name: /Question par question/ }));
    await user.click(within(dialog).getByRole('checkbox', { name: 'Appliquer les contrôles' }));
    await user.click(within(dialog).getByRole('button', { name: /Suivant/ }));

    expect(within(dialog).getByText('Cette question est obligatoire.')).toBeInTheDocument();
    expect(within(dialog).getByText('Question 1 sur 2')).toBeInTheDocument();

    await user.type(within(dialog).getByLabelText(/Première/), 'Une réponse');
    await user.click(within(dialog).getByRole('button', { name: /Suivant/ }));
    expect(within(dialog).getByText('Question 2 sur 2')).toBeInTheDocument();
  });
});
