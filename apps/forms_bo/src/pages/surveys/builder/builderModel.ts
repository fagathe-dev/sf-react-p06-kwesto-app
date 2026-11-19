import type { StructurePayload } from '@/services/api';
import type { BooleanConfig, ChoiceConfig, RangeConfig, DisplayCondition, FileConfig, PublicSurvey, Question, QuestionConfig, QuestionType, Section, Survey } from '@/types';
import { hasValidShape } from './conditions';
import { uuid } from '@/utils/format';

/** Modèle du builder : sections et questions dans l'ordre d'affichage, identifiants générés côté client. */

export interface BuilderSection {
  id: string;
  title: string;
  description: string;
  /** Affichage conditionnel de toute la section ; source : question d'une section précédente. */
  displayCondition: DisplayCondition | null;
}

export interface BuilderQuestion {
  id: string;
  sectionId: string | null;
  type: QuestionType;
  label: string;
  helpTitle: string;
  helpText: string;
  required: boolean;
  config: QuestionConfig;
  /** Affichage conditionnel (specs_fonc §3.5) ; null = toujours affichée. */
  displayCondition: DisplayCondition | null;
}

export interface BuilderState {
  sections: BuilderSection[];
  questions: BuilderQuestion[];
}

export const ROOT = '__root__';

export const defaultConfig = (type: QuestionType): QuestionConfig => {
  switch (type) {
    case 'TEXT':
      return { multiline: false, maxLength: 255 };
    case 'NUMBER':
      return { allowDecimal: false };
    case 'CHOICE':
      // Libellés vides : « Option 1 », « Option 2 » ne sont que des placeholders
      return { widget: 'radio', options: [newOption(), newOption()] } satisfies ChoiceConfig;
    case 'DATE':
      return {};
    case 'FILE':
      return { allowedMimeTypes: ['application/pdf', 'image/jpeg', 'image/png'], maxFiles: 1 } satisfies FileConfig;
    case 'BOOLEAN':
      return { preset: 'YES_NO', trueLabel: 'Oui', falseLabel: 'Non', widget: 'buttons', defaultValue: null } satisfies BooleanConfig;
    case 'RANGE':
      return { min: 0, max: 10, step: 1, minLabel: 'Pas du tout', maxLabel: 'Absolument', unit: '/10', defaultValue: null } satisfies RangeConfig;
  }
};

export const newOption = (label = '') => ({ id: uuid().slice(0, 8), label });

export const newQuestion = (type: QuestionType, sectionId: string | null): BuilderQuestion => ({
  id: uuid(),
  sectionId,
  type,
  label: '',
  helpTitle: '',
  helpText: '',
  required: true,
  config: defaultConfig(type),
  displayCondition: null,
});

export const fromSurvey = (survey: Survey): BuilderState => {
  const sections = [...survey.sections].sort((a, b) => a.position - b.position);
  const questions = [...survey.questions].sort((a, b) => a.position - b.position);

  return {
    sections: sections.map((s: Section) => ({ id: s.id, title: s.title, description: s.description ?? '', displayCondition: s.displayCondition ?? null })),
    questions: questions.map((q: Question) => ({
      id: q.id,
      sectionId: q.sectionId,
      type: q.type,
      label: q.label,
      helpTitle: q.helpTitle ?? '',
      helpText: q.helpText ?? '',
      required: q.required,
      config: q.config,
      displayCondition: q.displayCondition ?? null,
    })),
  };
};

/**
 * Condition envoyée à l'API : seulement si elle est complète (valeur saisie, forme conforme au type de la source).
 * Une condition en cours de saisie reste dans le builder sans bloquer l'enregistrement du reste.
 */
const conditionToSend = (state: BuilderState, { displayCondition: condition }: { displayCondition: DisplayCondition | null }): DisplayCondition | null => {
  const source = condition ? state.questions.find((q) => q.id === condition.sourceQuestionId) : undefined;
  return condition && source && hasValidShape(condition, source) ? condition : null;
};

/** Questions d'un conteneur (section ou page unique), dans l'ordre global. */
export const questionsOf = (state: BuilderState, containerId: string): BuilderQuestion[] =>
  state.sections.length === 0
    ? state.questions
    : state.questions.filter((q) => (q.sectionId ?? state.sections[0]?.id) === containerId);

/**
 * Ordre d'affichage final : sections dans l'ordre, puis questions de chaque section.
 * La numérotation est continue sur tout le questionnaire (specs_fonc §3.2).
 */
export const orderedQuestions = (state: BuilderState): BuilderQuestion[] =>
  state.sections.length === 0 ? state.questions : state.sections.flatMap((section) => questionsOf(state, section.id));

export const numbering = (state: BuilderState): Map<string, number> =>
  new Map(orderedQuestions(state).map((question, index) => [question.id, index + 1]));

export const toPayload = (state: BuilderState): StructurePayload => ({
  sections: state.sections.map((s) => ({ id: s.id, title: s.title, description: s.description || null, displayCondition: conditionToSend(state, s) })),
  questions: orderedQuestions(state).map((q) => ({
    id: q.id,
    sectionId: state.sections.length === 0 ? null : (q.sectionId ?? state.sections[0]?.id ?? null),
    type: q.type,
    label: q.label,
    helpTitle: q.helpTitle || null,
    helpText: q.helpText || null,
    required: q.required,
    config: q.config,
    displayCondition: conditionToSend(state, q),
  })),
});

/**
 * Questionnaire tel que le participant le recevrait, construit depuis l'état courant du builder
 * (modifications pas encore enregistrées comprises) : sert à l'aperçu.
 */
export const toPublicSurvey = (meta: Pick<PublicSurvey, 'id' | 'title' | 'description' | 'expiresAt' | 'displayMode' | 'showDuration'>, state: BuilderState): PublicSurvey => {
  const questions = orderedQuestions(state);

  return {
    ...meta,
    questionsCount: questions.length,
    stepper: state.sections.length >= 2,
    sections: state.sections.map((s, index) => ({ id: s.id, title: s.title, description: s.description || null, position: index + 1, displayCondition: conditionToSend(state, s) })),
    questions: questions.map((q, index) => ({
      id: q.id,
      sectionId: state.sections.length === 0 ? null : (q.sectionId ?? state.sections[0]?.id ?? null),
      type: q.type,
      label: q.label,
      helpTitle: q.helpTitle || null,
      helpText: q.helpText || null,
      required: q.required,
      position: index + 1,
      config: q.config,
      displayCondition: conditionToSend(state, q),
    })),
  };
};

/** Ajoute une section. La première section reçoit toutes les questions existantes. */
export const addSection = (state: BuilderState): BuilderState => {
  const section: BuilderSection = { id: uuid(), title: `Section ${state.sections.length + 1}`, description: '', displayCondition: null };

  if (state.sections.length === 0) {
    return { sections: [section], questions: state.questions.map((q) => ({ ...q, sectionId: section.id })) };
  }

  return { ...state, sections: [...state.sections, section] };
};

/**
 * Supprime une section : ses questions rejoignent la section précédente (ou la suivante).
 * Sans section restante, le formulaire redevient une page unique.
 */
export const removeSection = (state: BuilderState, sectionId: string): BuilderState => {
  const index = state.sections.findIndex((s) => s.id === sectionId);
  const sections = state.sections.filter((s) => s.id !== sectionId);
  const target = sections[Math.max(0, index - 1)]?.id ?? null;

  return {
    sections,
    questions: state.questions.map((q) => (q.sectionId === sectionId || sections.length === 0 ? { ...q, sectionId: target } : q)),
  };
};

/** Déplace une question vers un conteneur, avant la question `beforeId` (ou à la fin). */
export const moveQuestion = (state: BuilderState, questionId: string, containerId: string, beforeId: string | null): BuilderState => {
  const moving = state.questions.find((q) => q.id === questionId);
  if (!moving) return state;

  const sectionId = state.sections.length === 0 || containerId === ROOT ? (state.sections[0]?.id ?? null) : containerId;
  const updated = { ...moving, sectionId };
  const rest = orderedQuestions(state).filter((q) => q.id !== questionId);

  let insertAt: number;
  if (beforeId) {
    insertAt = rest.findIndex((q) => q.id === beforeId);
  } else {
    // Fin du conteneur : après la dernière question de la section cible
    const lastIndex = rest.map((q) => q.sectionId).lastIndexOf(sectionId);
    insertAt = lastIndex === -1 ? sectionInsertIndex(state, rest, sectionId) : lastIndex + 1;
  }

  const questions = [...rest];
  questions.splice(insertAt < 0 ? questions.length : insertAt, 0, updated);

  return { ...state, questions };
};

const sectionInsertIndex = (state: BuilderState, rest: BuilderQuestion[], sectionId: string | null): number => {
  // Section vide : avant la première question des sections suivantes
  const order = state.sections.map((s) => s.id);
  const position = sectionId ? order.indexOf(sectionId) : -1;
  const next = rest.findIndex((q) => order.indexOf(q.sectionId ?? '') > position);

  return next === -1 ? rest.length : next;
};

export const isFileConfig = (type: QuestionType, config: QuestionConfig): config is FileConfig => type === 'FILE' && 'maxFiles' in config;
