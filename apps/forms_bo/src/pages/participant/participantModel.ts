import type { AnswerValue, Answers, BooleanConfig, RangeConfig, ChoiceConfig, DateConfig, DisplayMode, FileConfig, NumberConfig, PublicSurvey, Question, TextConfig } from '@/types';

/**
 * Validation côté navigateur, identique aux règles du serveur (specs_fonc §4) : elle évite un aller-retour
 * mais le serveur reste seul juge (FormsCore\QuestionType\*Handler).
 */

export const isEmpty = (value: AnswerValue | undefined): boolean =>
  value === undefined || (Array.isArray(value) ? value.length === 0 : value.trim() === '');

// Espaces de milliers tolérés, y compris insécables (U+00A0) et fines insécables (U+202F)
const normalizeNumber = (raw: string): string => raw.trim().replace(/[\s\u00A0\u202F]/g, '').replace(',', '.');

const formatNumber = (value: number): string => value.toLocaleString('fr-FR', { maximumFractionDigits: 6 });

const frenchDate = (value: string): string => value.split('-').reverse().join('/');

export const validateAnswer = (question: Question, value: AnswerValue | undefined): string | null => {
  if (isEmpty(value)) {
    return question.required ? 'Cette question est obligatoire.' : null;
  }

  switch (question.type) {
    case 'TEXT': {
      const config = question.config as TextConfig;
      const text = String(value).trim();
      const length = config.multiline ? text.length : text.replace(/\s+/g, ' ').length;
      const max = config.maxLength ?? 5000;
      if (config.minLength !== undefined && length < config.minLength) return `La réponse doit contenir au moins ${config.minLength} caractères.`;
      if (length > max) return `La réponse ne peut pas dépasser ${max} caractères.`;
      return null;
    }

    case 'NUMBER': {
      const config = question.config as NumberConfig;
      const normalized = normalizeNumber(String(value));
      if (!/^[+-]?(\d+(\.\d+)?|\.\d+)$/.test(normalized)) return 'La réponse doit être un nombre valide.';
      const number = Number(normalized);
      if (!config.allowDecimal && !Number.isInteger(number)) return 'La réponse doit être un nombre entier.';
      if (config.min !== undefined && number < config.min) return `La valeur doit être supérieure ou égale à ${formatNumber(config.min)}.`;
      if (config.max !== undefined && number > config.max) return `La valeur doit être inférieure ou égale à ${formatNumber(config.max)}.`;
      return null;
    }

    case 'CHOICE': {
      const config = question.config as ChoiceConfig;
      const ids = Array.isArray(value) ? value : [String(value)];
      const allowed = new Set(config.options.map((o) => o.id));
      if (ids.some((id) => !allowed.has(id))) return 'Une option sélectionnée n\'existe pas dans cette question.';
      if (config.widget !== 'checkbox' && ids.length > 1) return 'Une seule option peut être sélectionnée.';
      return null;
    }

    case 'DATE': {
      const config = question.config as DateConfig;
      const date = String(value);
      const [y, m, d] = date.split('-').map(Number);
      const valid = /^\d{4}-\d{2}-\d{2}$/.test(date) && y !== undefined && m !== undefined && d !== undefined && new Date(Date.UTC(y, m - 1, d)).getUTCDate() === d;
      if (!valid) return 'La date est invalide.';
      if (config.minDate && date < config.minDate) return `La date doit être égale ou postérieure au ${frenchDate(config.minDate)}.`;
      if (config.maxDate && date > config.maxDate) return `La date doit être égale ou antérieure au ${frenchDate(config.maxDate)}.`;
      return null;
    }

    case 'FILE': {
      const config = question.config as FileConfig;
      const count = Array.isArray(value) ? value.length : 1;
      return count > config.maxFiles ? `Vous pouvez déposer au maximum ${config.maxFiles} fichier(s).` : null;
    }

    case 'BOOLEAN':
      return value === 'true' || value === 'false' ? null : 'La réponse doit être l\'une des deux valeurs proposées.';

    case 'RANGE': {
      const config = question.config as RangeConfig;
      const number = Number(String(value).replace(',', '.'));
      if (config.values && config.values.length > 0) {
        return config.values.some((v) => Math.abs(v - number) < 1e-9) ? null : 'La réponse doit être l\'une des valeurs de l\'échelle.';
      }
      const ratio = (number - config.min) / config.step;
      const onScale = Number.isFinite(number) && number >= config.min && number <= config.max && Math.abs(ratio - Math.round(ratio)) < 1e-9;
      return onScale ? null : `La réponse doit être comprise entre ${formatNumber(config.min)} et ${formatNumber(config.max)}.`;
    }
  }
};

/**
 * Réponses pré-remplies à l'ouverture : valeur par défaut des questions BOOLEAN (« Non » d'emblée,
 * le participant bascule sur « Oui » s'il le souhaite). Elles comptent comme des réponses.
 */
export const defaultAnswers = (questions: Question[]): Answers => {
  const answers: Answers = {};
  for (const question of questions) {
    if (question.type === 'RANGE') {
      const { defaultValue } = question.config as RangeConfig;
      if (defaultValue !== null && defaultValue !== undefined) answers[question.id] = String(defaultValue);
      continue;
    }
    if (question.type !== 'BOOLEAN') continue;
    const { defaultValue, widget } = question.config as BooleanConfig;
    const initial = defaultValue ?? (widget === 'switch' ? false : null);
    if (initial !== null) answers[question.id] = String(initial);
  }
  return answers;
};

export const validateQuestions = (questions: Question[], answers: Answers): Record<string, string> => {
  const errors: Record<string, string> = {};
  for (const question of questions) {
    const error = validateAnswer(question, answers[question.id]);
    if (error) errors[question.id] = error;
  }
  return errors;
};

/** Item du stepper : une section, numérotée par sa position (1, 2, 3…) et libellée par son titre. */
export interface StepperItem {
  id: string;
  position: number;
  title: string;
  description: string | null;
}

/** Un écran du formulaire. */
export interface FormPage {
  id: string;
  questions: Question[];
  /** Index de la section dans le stepper ; null sans stepper. */
  section: number | null;
  /** Premier écran de sa section : le titre et la description de la section y sont affichés. */
  firstOfSection: boolean;
}

export interface FormFlow {
  mode: DisplayMode;
  pages: FormPage[];
  /** Vide sans stepper (moins de 2 sections). */
  stepper: StepperItem[];
}

/**
 * Découpage du formulaire (specs_fonc §6.4), deux réglages indépendants :
 * - mode d'affichage : LIST (toutes les questions de l'écran ensemble) ou ONE_BY_ONE (une question par écran) ;
 * - stepper : uniquement à partir de 2 sections ; en LIST un écran par section, en ONE_BY_ONE le stepper
 *   suit la section de la question affichée.
 * Les questions sont toujours ordonnées par position.
 */
export const buildFlow = (survey: PublicSurvey, mode: DisplayMode = survey.displayMode, visible?: Set<string>): FormFlow => {
  // Questions conditionnelles masquées retirées ; une section dont toutes les questions sont masquées est sautée
  const questions = [...survey.questions].sort((a, b) => a.position - b.position).filter((q) => !visible || visible.has(q.id));
  const sections = survey.stepper
    ? [...survey.sections].sort((a, b) => a.position - b.position).filter((s) => !visible || questions.some((q) => q.sectionId === s.id) || !survey.questions.some((q) => q.sectionId === s.id))
    : [];
  const stepper = sections.map((s, index) => ({ id: s.id, position: index + 1, title: s.title, description: s.description }));
  const sectionIndex = (question: Question): number | null => {
    if (stepper.length === 0) return null;
    const index = sections.findIndex((s) => s.id === question.sectionId);
    return index === -1 ? 0 : index;
  };

  if (mode === 'ONE_BY_ONE' && questions.length > 0) {
    const pages = questions.map((question, index) => {
      const section = sectionIndex(question);
      const previous = questions[index - 1];
      return { id: question.id, questions: [question], section, firstOfSection: section !== null && (!previous || sectionIndex(previous) !== section) };
    });
    return { mode, pages, stepper };
  }

  if (stepper.length > 0) {
    const pages = sections.map((section, index) => ({ id: section.id, questions: questions.filter((q) => sectionIndex(q) === index), section: index, firstOfSection: true }));
    return { mode, pages, stepper };
  }

  return { mode, pages: [{ id: 'single', questions, section: null, firstOfSection: false }], stepper };
};

/** Formulaire sur un seul écran : la progression suit les réponses et non la navigation. */
const isSinglePage = (flow: FormFlow): boolean => flow.pages.length <= 1;

/**
 * Progression (specs_fonc §6.4), toujours affichée :
 * - un seul écran (liste sans sections) : questions renseignées / nombre total de questions ;
 * - plusieurs écrans : écran courant / nombre d'écrans, 100 % au dernier.
 */
export const progressPercent = (flow: FormFlow, current: number, answers: Answers): number => {
  if (isSinglePage(flow)) {
    const questions = flow.pages[0]?.questions ?? [];
    if (questions.length === 0) return 0;
    const answered = questions.filter((q) => !isEmpty(answers[q.id])).length;
    return Math.round((answered / questions.length) * 100);
  }

  return Math.round(((Math.min(current, flow.pages.length - 1) + 1) / flow.pages.length) * 100);
};

export const progressLabel = (flow: FormFlow, current: number, answers: Answers): string => {
  if (isSinglePage(flow)) {
    const questions = flow.pages[0]?.questions ?? [];
    const answered = questions.filter((q) => !isEmpty(answers[q.id])).length;
    const plural = answered > 1 ? 's' : '';
    return `${answered} question${plural} renseignée${plural} sur ${questions.length}`;
  }

  return flow.mode === 'ONE_BY_ONE' ? `Question ${current + 1} sur ${flow.pages.length}` : `Étape ${current + 1} sur ${flow.pages.length}`;
};

/** Corps envoyé à l'API : les nombres saisis gardent leur forme (la virgule est normalisée côté serveur). */
export const toPayload = (questions: Question[], answers: Answers): Answers => {
  const payload: Answers = {};
  for (const question of questions) {
    const value = answers[question.id];
    if (!isEmpty(value)) payload[question.id] = value as AnswerValue;
  }
  return payload;
};
