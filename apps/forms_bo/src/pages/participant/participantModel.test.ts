import { formatRangeValue, type DisplayMode, type PublicSurvey, type Question } from '@/types';
import { describe, expect, it } from 'vitest';
import { estimateSurvey, formatEstimate } from './estimate';
import { effectiveHelpText } from './helpText';
import { buildFlow, defaultAnswers, progressLabel, progressPercent, validateAnswer } from './participantModel';

const question = (type: Question['type'], config: Question['config'], required = true): Question => ({
  id: crypto.randomUUID(),
  sectionId: null,
  type,
  label: 'Question',
  helpTitle: null,
  helpText: null,
  required,
  position: 1,
  config,
});

describe('validateAnswer', () => {
  it('accepte la virgule comme séparateur décimal et contrôle l\'intervalle', () => {
    const q = question('NUMBER', { allowDecimal: true, min: 0, max: 10 });
    expect(validateAnswer(q, '7,5')).toBeNull();
    expect(validateAnswer(q, '10,5')).toMatch(/inférieure ou égale/);
    expect(validateAnswer(q, 'abc')).toMatch(/nombre valide/);
  });

  it('refuse une partie décimale quand seuls les entiers sont autorisés', () => {
    expect(validateAnswer(question('NUMBER', { allowDecimal: false }), '4,2')).toMatch(/entier/);
  });

  it('applique obligatoire et facultatif', () => {
    expect(validateAnswer(question('TEXT', {}), '  ')).toMatch(/obligatoire/);
    expect(validateAnswer(question('TEXT', {}, false), '')).toBeNull();
  });

  it('refuse plusieurs options pour un choix unique et une date hors bornes', () => {
    const options = [{ id: 'a', label: 'A' }, { id: 'b', label: 'B' }];
    expect(validateAnswer(question('CHOICE', { widget: 'radio', options }), ['a', 'b'])).toMatch(/Une seule/);
    expect(validateAnswer(question('CHOICE', { widget: 'checkbox', options }), ['a', 'b'])).toBeNull();
    expect(validateAnswer(question('DATE', { minDate: '2026-01-01' }), '2025-12-31')).toMatch(/postérieure/);
    expect(validateAnswer(question('DATE', {}), '2026-02-30')).toMatch(/invalide/);
  });
});

describe('durée estimée', () => {
  it('fourchette de questions avec les questions conditionnelles, au moins 1 minute', () => {
    const always = question('BOOLEAN', { preset: 'YES_NO', trueLabel: 'Oui', falseLabel: 'Non', widget: 'buttons', defaultValue: null });
    const conditional = { ...question('TEXT', { multiline: true }), displayCondition: { sourceQuestionId: always.id, operator: 'EQUALS' as const, value: true } };

    expect(formatEstimate(estimateSurvey([always]), true)).toBe('Durée estimée : 1 minute · 1 question');
    expect(formatEstimate(estimateSurvey([always, conditional]), true)).toBe('Durée estimée : 1 à 2 minutes · 1 à 2 questions');
    // Durée masquée : le nombre de questions reste affiché
    expect(formatEstimate(estimateSurvey([always, conditional]), false)).toBe('1 à 2 questions');
  });
});

describe('aide par défaut', () => {
  const options = [{ id: 'a', label: 'A' }, { id: 'b', label: 'B' }];

  it('choix multiple sans aide : « Plusieurs réponses possibles. » ; l\'aide du créateur prime', () => {
    expect(effectiveHelpText(question('CHOICE', { widget: 'checkbox', options }))).toBe('Plusieurs réponses possibles.');
    expect(effectiveHelpText({ ...question('CHOICE', { widget: 'checkbox', options }), helpText: 'Trois au maximum.' })).toBe('Trois au maximum.');
    expect(effectiveHelpText(question('CHOICE', { widget: 'radio', options }))).toBeNull();
  });
});

describe('RANGE', () => {
  const range = (defaultValue: number | null = null) => question('RANGE', { min: 0, max: 20, step: 0.5, unit: '/20', defaultValue });

  it('accepte une valeur de l\'échelle, refuse hors bornes ou hors cran', () => {
    expect(validateAnswer(range(), '15.5')).toBeNull();
    expect(validateAnswer(range(), '20')).toBeNull();
    expect(validateAnswer(range(), '21')).toMatch(/entre 0 et 20/);
    expect(validateAnswer(range(), '7.3')).toMatch(/entre 0 et 20/);
    expect(validateAnswer(range(), '')).toMatch(/obligatoire/);
  });

  it('valeurs personnalisées : seules les valeurs de la liste sont acceptées', () => {
    const custom = question('RANGE', { min: 2, max: 79, step: 1, values: [2, 4, 6, 22, 40, 79], defaultValue: null });
    expect(validateAnswer(custom, '22')).toBeNull();
    expect(validateAnswer(custom, '5')).toMatch(/l'une des valeurs/);
  });

  it('pré-remplit la valeur de départ ; sans elle, pas de réponse', () => {
    const withDefault = range(10);
    const without = range();
    expect(defaultAnswers([withDefault, without])).toEqual({ [withDefault.id]: '10' });
  });

  it('affiche la valeur avec son suffixe', () => {
    expect(formatRangeValue(8, '/10')).toBe('8/10');
    expect(formatRangeValue(7.5, '/20')).toBe('7,5/20');
    expect(formatRangeValue(75, '%')).toBe('75 %');
    expect(formatRangeValue(3)).toBe('3');
  });
});

describe('BOOLEAN', () => {
  const boolean = (widget: 'buttons' | 'switch', defaultValue: boolean | null, required = true) =>
    question('BOOLEAN', { preset: 'YES_NO', trueLabel: 'Oui', falseLabel: 'Non', widget, defaultValue }, required);

  it('« false » est une réponse ; toute autre valeur est refusée', () => {
    const q = boolean('buttons', null);
    expect(validateAnswer(q, 'false')).toBeNull();
    expect(validateAnswer(q, 'true')).toBeNull();
    expect(validateAnswer(q, 'peut-être')).toMatch(/deux valeurs/);
    expect(validateAnswer(q, '')).toMatch(/obligatoire/);
  });

  it('pré-remplit la valeur par défaut ; un interrupteur sans valeur par défaut vaut « faux »', () => {
    const none = boolean('buttons', null);
    const no = boolean('buttons', false);
    const yes = boolean('switch', true);
    const switchWithoutDefault = boolean('switch', null);

    expect(defaultAnswers([none, no, yes, switchWithoutDefault, question('TEXT', {})])).toEqual({
      [no.id]: 'false',
      [yes.id]: 'true',
      [switchWithoutDefault.id]: 'false',
    });
  });
});

describe('parcours et progression', () => {
  /** 4 questions réparties dans `sections` sections : q0 → s0, q1 → s1, q2 → s0… */
  const survey = (sections: number, displayMode: DisplayMode = 'LIST'): PublicSurvey => {
    const sectionList = Array.from({ length: sections }, (_, i) => ({ id: `s${i}`, title: `Section ${i + 1}`, description: null, position: i + 1 }));
    return {
      id: 'x',
      title: 'Test',
      description: null,
      expiresAt: null,
      questionsCount: 4,
      displayMode,
      showDuration: true,
      stepper: sections >= 2,
      sections: sectionList,
      questions: Array.from({ length: 4 }, (_, i) => ({ ...question('TEXT', {}), position: i + 1, sectionId: sections ? `s${i % sections}` : null })),
    };
  };

  it('liste sans sections : un seul écran, pas de stepper, progression = questions renseignées / total', () => {
    const flow = buildFlow(survey(0));
    expect(flow.pages).toHaveLength(1);
    expect(flow.stepper).toEqual([]);
    const [q1, q2] = flow.pages[0]!.questions;
    const answers = { [q1!.id]: 'oui', [q2!.id]: 'oui' };
    expect(progressPercent(flow, 0, answers)).toBe(50);
    expect(progressLabel(flow, 0, answers)).toBe('2 questions renseignées sur 4');
    expect(progressLabel(flow, 0, {})).toBe('0 question renseignée sur 4');
  });

  it('liste avec sections : un écran par section, stepper numéroté par position (étape 2 sur 4 = 50 %)', () => {
    const flow = buildFlow(survey(4));
    expect(flow.pages).toHaveLength(4);
    expect(flow.stepper.map((s) => [s.position, s.title])).toEqual([
      [1, 'Section 1'],
      [2, 'Section 2'],
      [3, 'Section 3'],
      [4, 'Section 4'],
    ]);
    expect(progressPercent(flow, 1, {})).toBe(50);
    expect(progressLabel(flow, 1, {})).toBe('Étape 2 sur 4');
    expect(progressPercent(flow, 3, {})).toBe(100);
  });

  it('une seule section : pas de stepper', () => {
    expect(buildFlow(survey(1)).stepper).toEqual([]);
    expect(buildFlow(survey(1), 'ONE_BY_ONE').pages.every((p) => p.section === null)).toBe(true);
  });

  it('question par question sans sections : un écran par question, pas de stepper', () => {
    const flow = buildFlow(survey(0, 'ONE_BY_ONE'));
    expect(flow.pages).toHaveLength(4);
    expect(flow.pages.every((p) => p.questions.length === 1 && p.section === null)).toBe(true);
    expect(progressLabel(flow, 0, {})).toBe('Question 1 sur 4');
    expect(progressPercent(flow, 0, {})).toBe(25);
  });

  it('question par question avec sections : questions regroupées par section, le stepper suit la section', () => {
    const flow = buildFlow(survey(2), 'ONE_BY_ONE');
    // Ordre des positions : q0 (s0), q1 (s1), q2 (s0), q3 (s1) → l'ordre des positions prime
    expect(flow.pages.map((p) => p.section)).toEqual([0, 1, 0, 1]);
    expect(flow.pages.map((p) => p.firstOfSection)).toEqual([true, true, true, true]);
    expect(flow.stepper).toHaveLength(2);
  });
});
