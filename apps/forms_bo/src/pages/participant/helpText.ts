import type { ChoiceConfig, QuestionType } from '@/types';

/** Aide affichée par défaut sous une question à choix multiple sans texte d'aide. */
export const MULTIPLE_CHOICE_HELP = 'Plusieurs réponses possibles.';

/**
 * Texte d'aide effectivement affiché au participant : celui du créateur, sinon l'aide par défaut du type
 * (choix multiple), sinon rien.
 */
export const effectiveHelpText = (question: { type: QuestionType; helpText: string | null; config: unknown }): string | null => {
  const own = question.helpText?.trim();
  if (own) return own;
  if (question.type === 'CHOICE' && (question.config as ChoiceConfig).widget === 'checkbox') return MULTIPLE_CHOICE_HELP;
  return null;
};
