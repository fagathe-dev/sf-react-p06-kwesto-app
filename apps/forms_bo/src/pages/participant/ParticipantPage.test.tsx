import type { OpenResponse } from '@/types';
import { appTheme } from '@/ui/theme';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from 'styled-components';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ParticipantPage } from './ParticipantPage';

const openSurvey = vi.fn();
const submitAnswers = vi.fn();
const saveDraft = vi.fn();
const discardDraft = vi.fn();

vi.mock('@/services/participant.api', () => ({
  openSurvey: (...args: unknown[]) => openSurvey(...args),
  submitAnswers: (...args: unknown[]) => submitAnswers(...args),
  saveDraft: (...args: unknown[]) => saveDraft(...args),
  discardDraft: (...args: unknown[]) => discardDraft(...args),
  uploadAsset: vi.fn(),
  deleteAsset: vi.fn(),
}));

const TOKEN = 'a'.repeat(64);

const stepperSurvey: OpenResponse = {
  state: 'OPEN',
  pendingAssets: {},
  config: { uploadMaxSizeMb: 10, fileFormats: [] },
  survey: {
    id: 's',
    title: 'Baromètre',
    description: 'Consignes',
    expiresAt: '2030-01-01T18:00:00+01:00',
    questionsCount: 2,
    displayMode: 'LIST',
    showDuration: true,
    stepper: true,
    sections: [
      { id: 'sec1', title: 'Première étape', description: 'Introduction', position: 1 },
      { id: 'sec2', title: 'Seconde étape', description: null, position: 2 },
    ],
    questions: [
      { id: 'q1', sectionId: 'sec1', type: 'NUMBER', label: 'Votre note', helpTitle: 'À savoir', helpText: 'De 0 à 10.', required: true, position: 1, config: { allowDecimal: true, min: 0, max: 10 } },
      { id: 'q2', sectionId: 'sec2', type: 'TEXT', label: 'Un commentaire', helpTitle: null, helpText: null, required: false, position: 2, config: { multiline: true, maxLength: 500 } },
    ],
  },
};

const renderPage = () =>
  render(
    <ThemeProvider theme={appTheme}>
      <MemoryRouter initialEntries={[`/s/${TOKEN}`]}>
        <Routes>
          <Route path="/s/:token" element={<ParticipantPage />} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>
  );

describe('ParticipantPage', () => {
  beforeEach(() => {
    openSurvey.mockReset();
    submitAnswers.mockReset();
    saveDraft.mockReset();
    discardDraft.mockReset();
    saveDraft.mockResolvedValue({ savedAt: '2026-11-16T14:32:00+01:00' });
    discardDraft.mockResolvedValue(null);
  });

  it('brouillon : reprendre au même écran avec les réponses conservées', async () => {
    const user = userEvent.setup();
    openSurvey.mockResolvedValue({ ...stepperSurvey, draft: { answers: { q1: '7', q2: 'Déjà commencé' }, questionId: 'q2', savedAt: '2026-11-15T18:05:00+01:00' } });
    renderPage();

    expect(await screen.findByText(/Vous avez commencé ce questionnaire le/)).toBeInTheDocument();
    expect(screen.getByText(/2 réponses déjà saisies/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Reprendre où j'en étais/ }));

    // Reprise sur l'étape 2, réponses conservées
    expect(screen.getByText('Étape 2 sur 2')).toBeInTheDocument();
    expect(screen.getByLabelText(/Un commentaire/)).toHaveValue('Déjà commencé');
    await user.click(screen.getByRole('button', { name: /Précédent/ }));
    expect(screen.getByLabelText(/Votre note/)).toHaveValue('7');
    expect(discardDraft).not.toHaveBeenCalled();
  });

  it('brouillon : recommencer efface les réponses et repart du début', async () => {
    const user = userEvent.setup();
    openSurvey.mockResolvedValue({ ...stepperSurvey, draft: { answers: { q1: '7' }, questionId: 'q2', savedAt: '2026-11-15T18:05:00+01:00' } });
    renderPage();

    await user.click(await screen.findByRole('button', { name: /Recommencer/ }));
    expect(discardDraft).toHaveBeenCalledWith(TOKEN);
    expect(await screen.findByText('Étape 1 sur 2')).toBeInTheDocument();
    expect(screen.getByLabelText(/Votre note/)).toHaveValue('');
  });

  it('brouillon : enregistrement automatique pendant la saisie', async () => {
    const user = userEvent.setup();
    openSurvey.mockResolvedValue(stepperSurvey);
    renderPage();

    // Première visite : pas d'écran de reprise
    await user.type(await screen.findByLabelText(/Votre note/), '8');
    await waitFor(() => expect(saveDraft).toHaveBeenCalledWith(TOKEN, { q1: '8' }, 'q1'), { timeout: 2000 });
    expect(await screen.findByText(/Réponses enregistrées à .* : vous pourrez reprendre plus tard/)).toBeInTheDocument();
  });

  it('affiche l\'écran « déjà répondu » sans formulaire', async () => {
    openSurvey.mockResolvedValue({ state: 'ANSWERED', message: 'Vous avez déjà répondu à ce questionnaire le 13 novembre. Une seule participation est autorisée.', survey: { title: 'Baromètre' } });
    renderPage();

    expect(await screen.findByText(/Une seule participation est autorisée/)).toBeInTheDocument();
    expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
  });

  it('parcours en étapes : progression, aide reliée au champ, « Suivant » bloquant, envoi unique', async () => {
    const user = userEvent.setup();
    openSurvey.mockResolvedValue(stepperSurvey);
    submitAnswers.mockResolvedValue({ state: 'ANSWERED', message: 'Merci !', submittedAt: '2026-11-14T10:00:00+01:00' });
    renderPage();

    const progress = await screen.findByRole('progressbar', { name: 'Progression du questionnaire' });
    expect(progress).toHaveAttribute('aria-valuenow', '50');
    expect(screen.getByText('Étape 1 sur 2')).toBeInTheDocument();

    // Stepper : un item par section, numéroté par sa position et libellé par son titre
    const stepper = screen.getByRole('list', { name: 'Sections du questionnaire' });
    const items = within(stepper).getAllByRole('listitem');
    expect(items.map((item) => item.textContent)).toEqual(['1Section 1 : Première étape', '2Section 2 : Seconde étape']);
    expect(items[0]).toHaveAttribute('aria-current', 'step');

    // Encart d'aide lu après l'intitulé et relié au champ
    const input = screen.getByLabelText(/Votre note/);
    expect(input).toHaveAttribute('aria-describedby', expect.stringContaining('q-q1-help'));
    expect(screen.getByText('De 0 à 10.')).toBeInTheDocument();

    // Pas de bouton d'envoi avant la dernière étape ; « Suivant » valide l'étape
    expect(screen.queryByRole('button', { name: /Envoyer/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Suivant/ }));
    expect(await screen.findByText('Cette question est obligatoire.')).toBeInTheDocument();

    await user.type(input, '12');
    await user.click(screen.getByRole('button', { name: /Suivant/ }));
    expect(await screen.findByText(/inférieure ou égale à 10/)).toBeInTheDocument();

    await user.clear(input);
    await user.type(input, '7,5');
    await user.click(screen.getByRole('button', { name: /Suivant/ }));

    expect(await screen.findByText('Étape 2 sur 2')).toBeInTheDocument();
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '100');

    // « Précédent » conserve les réponses
    await user.click(screen.getByRole('button', { name: /Précédent/ }));
    expect(screen.getByLabelText(/Votre note/)).toHaveValue('7,5');
    await user.click(screen.getByRole('button', { name: /Suivant/ }));

    await user.click(screen.getByRole('button', { name: /Envoyer mes réponses/ }));
    await waitFor(() => expect(submitAnswers).toHaveBeenCalledWith(TOKEN, { q1: '7,5' }));
    expect(await screen.findByText('Merci pour votre participation !')).toBeInTheDocument();
  });

  it('liste sans sections : un seul écran, pas de stepper, progression selon les réponses', async () => {
    const user = userEvent.setup();
    openSurvey.mockResolvedValue({ ...stepperSurvey, survey: { ...stepperSurvey.survey, stepper: false, sections: [], questions: stepperSurvey.survey.questions.map((q) => ({ ...q, sectionId: null })) } });
    renderPage();

    expect(await screen.findByText('0 question renseignée sur 2')).toBeInTheDocument();
    expect(screen.queryByRole('list', { name: 'Sections du questionnaire' })).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Votre note/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Un commentaire/)).toBeInTheDocument();

    await user.type(screen.getByLabelText(/Votre note/), '8');
    expect(screen.getByText('1 question renseignée sur 2')).toBeInTheDocument();
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '50');

    // Entrée dans un champ n'envoie jamais le questionnaire
    await user.keyboard('{Enter}');
    expect(submitAnswers).not.toHaveBeenCalled();
  });

  it('BOOLEAN : interrupteur pré-réglé sur « Non », bascule sur « Oui », boutons sans valeur par défaut', async () => {
    const user = userEvent.setup();
    submitAnswers.mockResolvedValue({ state: 'ANSWERED', message: 'Merci !', submittedAt: '2026-11-14T10:00:00+01:00' });
    openSurvey.mockResolvedValue({
      ...stepperSurvey,
      survey: {
        ...stepperSurvey.survey,
        stepper: false,
        sections: [],
        questions: [
          { id: 'b1', sectionId: null, type: 'BOOLEAN', label: 'Êtes-vous satisfait ?', helpTitle: null, helpText: null, required: true, position: 1, config: { preset: 'YES_NO', trueLabel: 'Oui', falseLabel: 'Non', widget: 'switch', defaultValue: false } },
          { id: 'b2', sectionId: null, type: 'BOOLEAN', label: 'Recommanderiez-vous ?', helpTitle: null, helpText: null, required: false, position: 2, config: { preset: 'CUSTOM', trueLabel: 'Volontiers', falseLabel: 'Pas vraiment', widget: 'buttons', defaultValue: null } },
        ],
      },
    });
    renderPage();

    // La valeur par défaut compte comme réponse
    const toggle = await screen.findByRole('switch', { name: /Êtes-vous satisfait/ });
    expect(toggle).not.toBeChecked();
    expect(screen.getByText('Non')).toBeInTheDocument();
    expect(screen.getByText('1 question renseignée sur 2')).toBeInTheDocument();

    await user.click(toggle);
    expect(toggle).toBeChecked();
    expect(screen.getByText('Oui')).toBeInTheDocument();

    // Boutons : rien de présélectionné, question facultative laissée vide
    const group = screen.getByRole('radiogroup', { name: /Recommanderiez-vous/ });
    expect(within(group).getByRole('radio', { name: 'Volontiers' })).not.toBeChecked();
    expect(within(group).getByRole('radio', { name: 'Pas vraiment' })).not.toBeChecked();

    await user.click(screen.getByRole('button', { name: /Envoyer mes réponses/ }));
    await waitFor(() => expect(submitAnswers).toHaveBeenCalledWith(TOKEN, { b1: 'true' }));
  });

  it('intitulé et aide en Markdown, durée estimée masquable', async () => {
    openSurvey.mockResolvedValue({
      ...stepperSurvey,
      survey: {
        ...stepperSurvey.survey,
        questions: [{ ...stepperSurvey.survey.questions[0]!, label: 'Votre **note** globale', helpText: '- de 0 à 10\n- [barème](https://exemple.fr)' }, stepperSurvey.survey.questions[1]!],
      },
    });
    const { unmount } = renderPage();

    expect(await screen.findByText('note', { selector: 'strong' })).toBeInTheDocument();
    expect(screen.getByLabelText(/Votre note globale/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'barème' })).toHaveAttribute('href', 'https://exemple.fr');
    expect(screen.getByText(/Durée estimée : \d+ (à \d+ )?minutes? · 2 questions/)).toBeInTheDocument();
    unmount();

    // Questionnaire long : le créateur a masqué la durée ; le nombre de questions reste affiché
    openSurvey.mockResolvedValue({ ...stepperSurvey, survey: { ...stepperSurvey.survey, showDuration: false } });
    renderPage();
    await screen.findByText('Étape 1 sur 2');
    expect(screen.queryByText(/Durée estimée/)).not.toBeInTheDocument();
    expect(screen.getByText('2 questions')).toBeInTheDocument();
  });

  it('choix à 10 options et plus : recherche dans les cases à cocher, saisie semi-automatique pour la liste', async () => {
    const user = userEvent.setup();
    const regions = ['Auvergne-Rhône-Alpes', 'Bourgogne-Franche-Comté', 'Bretagne', 'Centre-Val de Loire', 'Corse', 'Grand Est', 'Hauts-de-France', 'Île-de-France', 'Normandie', 'Nouvelle-Aquitaine', 'Occitanie'];
    const options = regions.map((label, i) => ({ id: `r${i}`, label }));
    submitAnswers.mockResolvedValue({ state: 'ANSWERED', message: 'Merci !', submittedAt: '2026-11-14T10:00:00+01:00' });
    openSurvey.mockResolvedValue({
      ...stepperSurvey,
      survey: {
        ...stepperSurvey.survey,
        stepper: false,
        sections: [],
        questions: [
          { id: 'visited', sectionId: null, type: 'CHOICE', label: 'Régions visitées', helpTitle: null, helpText: null, required: false, position: 1, config: { widget: 'checkbox', options } },
          { id: 'home', sectionId: null, type: 'CHOICE', label: 'Votre région', helpTitle: null, helpText: null, required: true, position: 2, config: { widget: 'select', options } },
        ],
      },
    });
    renderPage();

    // Cases à cocher : filtre insensible aux accents, les réponses cochées restent visibles
    // Le fieldset de la question et le groupe des cases portent le même nom : on prend le groupe des cases
    const visited = (await screen.findAllByRole('group', { name: /Régions visitées/ })).at(-1)!;
    await user.click(within(visited).getByRole('checkbox', { name: 'Bretagne' }));
    await user.type(within(visited).getByRole('searchbox', { name: 'Filtrer les réponses' }), 'ile');
    expect(within(visited).getAllByRole('checkbox').map((c) => c.parentElement?.textContent)).toEqual(['Bretagne', 'Île-de-France']);
    expect(within(visited).getByText('2 réponses sur 11')).toBeInTheDocument();

    // Liste : saisie semi-automatique au clavier
    const combobox = screen.getByRole('combobox', { name: /Votre région/ });
    await user.type(combobox, 'norm');
    expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(['Normandie']);
    await user.keyboard('{ArrowDown}{Enter}');
    expect(combobox).toHaveValue('Normandie');
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /Envoyer mes réponses/ }));
    await waitFor(() => expect(submitAnswers).toHaveBeenCalledWith(TOKEN, { visited: ['r2'], home: ['r8'] }));
  });

  it('échelle : curseur sans réponse au départ, déplacé au clavier, valeur envoyée', async () => {
    const user = userEvent.setup();
    submitAnswers.mockResolvedValue({ state: 'ANSWERED', message: 'Merci !', submittedAt: '2026-11-14T10:00:00+01:00' });
    openSurvey.mockResolvedValue({
      ...stepperSurvey,
      survey: {
        ...stepperSurvey.survey,
        stepper: false,
        sections: [],
        questions: [
          {
            id: 'nps',
            sectionId: null,
            type: 'RANGE',
            label: 'Recommanderiez-vous ce service à votre entourage ?',
            helpTitle: null,
            helpText: '0 = Pas du tout · 10 = Tout à fait',
            required: true,
            position: 1,
            config: { min: 0, max: 10, step: 1, minLabel: 'Pas du tout', maxLabel: 'Absolument', unit: '/10', defaultValue: null },
          },
        ],
      },
    });
    renderPage();

    const slider = await screen.findByRole('slider', { name: /Recommanderiez-vous ce service/ });
    expect(slider).toHaveAttribute('aria-valuetext', 'Déplacez le curseur');
    expect(screen.getByText('Pas du tout')).toBeInTheDocument();
    expect(screen.getByText('Absolument')).toBeInTheDocument();

    // Obligatoire et pas encore déplacé : l'envoi est bloqué
    await user.click(screen.getByRole('button', { name: /Envoyer mes réponses/ }));
    expect(await screen.findByText('Cette question est obligatoire.')).toBeInTheDocument();

    fireEvent.change(slider, { target: { value: '8' } });
    expect(screen.getByText('8/10')).toBeInTheDocument();
    expect(slider).toHaveAttribute('aria-valuetext', '8/10');

    await user.click(screen.getByRole('button', { name: /Envoyer mes réponses/ }));
    await waitFor(() => expect(submitAnswers).toHaveBeenCalledWith(TOKEN, { nps: '8' }));
  });

  it('question conditionnelle : nom de jeune fille seulement si Madame, obligatoire seulement si visible', async () => {
    const user = userEvent.setup();
    submitAnswers.mockResolvedValue({ state: 'ANSWERED', message: 'Merci !', submittedAt: '2026-11-14T10:00:00+01:00' });
    openSurvey.mockResolvedValue({
      ...stepperSurvey,
      survey: {
        ...stepperSurvey.survey,
        stepper: false,
        sections: [],
        questions: [
          { id: 'civ', sectionId: null, type: 'CHOICE', label: 'Civilité', helpTitle: null, helpText: null, required: true, position: 1, config: { widget: 'radio', options: [{ id: 'mr', label: 'Monsieur' }, { id: 'mme', label: 'Madame' }] }, displayCondition: null },
          { id: 'maiden', sectionId: null, type: 'TEXT', label: 'Nom de jeune fille', helpTitle: null, helpText: null, required: true, position: 2, config: { multiline: false, maxLength: 120 }, displayCondition: { sourceQuestionId: 'civ', operator: 'EQUALS', value: 'mme' } },
        ],
      },
    });
    renderPage();

    // Masquée tant que la civilité n'est pas « Madame » ; la numérotation du builder est conservée
    await screen.findByText('Civilité');
    expect(screen.queryByLabelText(/Nom de jeune fille/)).not.toBeInTheDocument();
    expect(screen.getByText('0 question renseignée sur 1')).toBeInTheDocument();

    await user.click(screen.getByRole('radio', { name: 'Madame' }));
    const maiden = screen.getByLabelText(/Nom de jeune fille/);
    expect(screen.getByText('2.')).toBeInTheDocument();
    expect(screen.getByRole('status', { hidden: true }).textContent).toContain('Nouvelle question : Nom de jeune fille');

    // Visible et obligatoire : bloque l'envoi
    await user.click(screen.getByRole('button', { name: /Envoyer mes réponses/ }));
    expect(await screen.findByText('Cette question est obligatoire.')).toBeInTheDocument();
    expect(submitAnswers).not.toHaveBeenCalled();

    // Saisie puis retour à « Monsieur » : la question disparaît et sa valeur n'est pas envoyée
    await user.type(maiden, 'Durand');
    await user.click(screen.getByRole('radio', { name: 'Monsieur' }));
    expect(screen.queryByLabelText(/Nom de jeune fille/)).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /Envoyer mes réponses/ }));
    await waitFor(() => expect(submitAnswers).toHaveBeenCalledWith(TOKEN, { civ: ['mr'] }));
  });

  it('question par question : un écran par question, Entrée passe à la suivante, stepper par section', async () => {
    const user = userEvent.setup();
    openSurvey.mockResolvedValue({ ...stepperSurvey, survey: { ...stepperSurvey.survey, displayMode: 'ONE_BY_ONE' } });
    submitAnswers.mockResolvedValue({ state: 'ANSWERED', message: 'Merci !', submittedAt: '2026-11-14T10:00:00+01:00' });
    renderPage();

    expect(await screen.findByText('Question 1 sur 2')).toBeInTheDocument();
    expect(screen.queryByLabelText(/Un commentaire/)).not.toBeInTheDocument();
    expect(within(screen.getByRole('list', { name: 'Sections du questionnaire' })).getAllByRole('listitem')[0]).toHaveAttribute('aria-current', 'step');

    await user.type(screen.getByLabelText(/Votre note/), '9{Enter}');

    expect(await screen.findByText('Question 2 sur 2')).toBeInTheDocument();
    expect(screen.getByLabelText(/Un commentaire/)).toHaveFocus();
    expect(within(screen.getByRole('list', { name: 'Sections du questionnaire' })).getAllByRole('listitem')[1]).toHaveAttribute('aria-current', 'step');

    await user.click(screen.getByRole('button', { name: /Envoyer mes réponses/ }));
    await waitFor(() => expect(submitAnswers).toHaveBeenCalledWith(TOKEN, { q1: '9' }));
  });
});
