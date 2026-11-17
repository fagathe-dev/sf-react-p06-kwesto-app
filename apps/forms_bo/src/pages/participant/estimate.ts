import type { ChoiceConfig, Question, TextConfig } from '@/types';

/**
 * Nombre de questions (toujours affiché) et durée estimée (option `showDuration`) présentés au participant (specs_fonc §6.4).
 * Estimation volontairement simple : un temps de réponse moyen par type, plus la lecture de l'intitulé et de l'aide.
 * Les questions conditionnelles donnent une fourchette (« 8 à 12 questions »).
 */

type EstimatedQuestion = Pick<Question, 'type' | 'label' | 'helpText' | 'config' | 'displayCondition'>;

export interface SurveyEstimate {
  minQuestions: number;
  maxQuestions: number;
  minMinutes: number;
  maxMinutes: number;
}

/** Au-delà, le builder suggère de masquer l'estimation pour limiter les abandons. */
export const LONG_SURVEY_MINUTES = 10;

const words = (text: string | null | undefined): number => (text ?? '').trim().split(/\s+/).filter(Boolean).length;

/** Secondes estimées pour répondre à une question : saisie selon le type + lecture (~4 mots/s). */
export const questionSeconds = (question: EstimatedQuestion): number => {
  const reading = (words(question.label) + words(question.helpText)) / 4;

  switch (question.type) {
    case 'TEXT':
      return reading + ((question.config as TextConfig).multiline ? 60 : 20);
    case 'CHOICE': {
      const config = question.config as ChoiceConfig;
      return reading + Math.min(40, 6 + config.options.length * 1.5) + (config.widget === 'checkbox' ? 5 : 0);
    }
    case 'NUMBER':
    case 'DATE':
      return reading + 12;
    case 'FILE':
      return reading + 60;
    case 'BOOLEAN':
      return reading + 5;
    case 'RANGE':
      return reading + 8;
  }
};

export const estimateSurvey = (questions: EstimatedQuestion[]): SurveyEstimate => {
  const always = questions.filter((q) => !q.displayCondition);
  const seconds = (list: EstimatedQuestion[]) => list.reduce((total, q) => total + questionSeconds(q), 0);

  const minMinutes = Math.max(1, Math.round((seconds(always) * 0.8) / 60));
  const maxMinutes = Math.max(minMinutes, Math.ceil((seconds(questions) * 1.25) / 60));

  return { minQuestions: always.length, maxQuestions: questions.length, minMinutes, maxMinutes };
};

const plural = (count: number, word: string) => `${word}${count > 1 ? 's' : ''}`;

/** « 12 questions », ou « 8 à 12 questions » avec des questions conditionnelles. Toujours affiché. */
export const formatQuestionCount = ({ minQuestions, maxQuestions }: SurveyEstimate): string =>
  minQuestions === maxQuestions ? `${maxQuestions} ${plural(maxQuestions, 'question')}` : `${minQuestions} à ${maxQuestions} questions`;

/** « Durée estimée : 3 à 5 minutes ». Affiché seulement si l'option `showDuration` est active. */
export const formatDuration = ({ minMinutes, maxMinutes }: SurveyEstimate): string =>
  `Durée estimée : ${minMinutes === maxMinutes ? `${minMinutes} ${plural(minMinutes, 'minute')}` : `${minMinutes} à ${maxMinutes} minutes`}`;

/** Ligne affichée sous la description : « Durée estimée : 3 à 5 minutes · 12 questions », ou « 12 questions ». */
export const formatEstimate = (estimate: SurveyEstimate, withDuration: boolean): string =>
  withDuration ? `${formatDuration(estimate)} · ${formatQuestionCount(estimate)}` : formatQuestionCount(estimate);
