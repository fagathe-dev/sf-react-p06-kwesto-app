import { QUESTION_TYPE_ICONS, QUESTION_TYPE_LABELS, type QuestionType } from '@/types';

export interface QuestionTypeOption {
  type: QuestionType;
  label: string;
  icon: string;
  description: string;
}

/** Types proposés dans le builder, dans l'ordre d'affichage (choix d'ajout et liste déroulante du type). */
export const QUESTION_TYPE_OPTIONS: QuestionTypeOption[] = (
  [
    ['TEXT', 'Réponse libre, courte ou longue'],
    ['CHOICE', 'Choix unique, multiple ou liste'],
    ['BOOLEAN', 'Oui ou non, boutons ou interrupteur'],
    ['RANGE', 'Curseur sur une échelle (note de 0 à 10…)'],
    ['NUMBER', 'Nombre, avec bornes et unité'],
    ['DATE', 'Date, avec bornes'],
    ['FILE', 'Dépôt de documents'],
  ] as const
).map(([type, description]) => ({ type, description, label: QUESTION_TYPE_LABELS[type], icon: QUESTION_TYPE_ICONS[type] }));
