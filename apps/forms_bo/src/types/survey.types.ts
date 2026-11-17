/** Contrats JSON de forms_api (groupes de sérialisation survey.list / survey.read / survey.public). */

export type Uuid = string;
export type IsoDate = string;

export type SurveyStatus = 'DRAFT' | 'VALIDATED' | 'ACTIVE' | 'CLOSED';
export type CloseReason = 'ALL_ANSWERED' | 'DEADLINE_REACHED' | 'MANUAL';
export type QuestionType = 'TEXT' | 'NUMBER' | 'CHOICE' | 'DATE' | 'FILE' | 'BOOLEAN' | 'RANGE';
export type ChoiceWidget = 'radio' | 'checkbox' | 'select';
export type BooleanPreset = 'YES_NO' | 'TRUE_FALSE' | 'CUSTOM';
export type BooleanWidget = 'buttons' | 'switch';

export interface TextConfig {
  multiline?: boolean;
  minLength?: number;
  maxLength?: number;
  placeholder?: string;
}

export interface NumberConfig {
  allowDecimal?: boolean;
  min?: number;
  max?: number;
  step?: number;
  unit?: string;
}

export interface ChoiceOption {
  id: string;
  label: string;
}

export interface ChoiceConfig {
  widget: ChoiceWidget;
  multiple?: boolean;
  options: ChoiceOption[];
}

export interface DateConfig {
  minDate?: string;
  maxDate?: string;
}

export interface FileConfig {
  allowedMimeTypes: string[];
  maxSizeMb?: number;
  maxFiles: number;
}

/**
 * BOOLEAN (specs_fonc §4.6) : libellés des deux valeurs, rendu en deux boutons ou en interrupteur,
 * valeur pré-remplie (un interrupteur en a toujours une).
 */
export interface BooleanConfig {
  preset: BooleanPreset;
  trueLabel: string;
  falseLabel: string;
  widget: BooleanWidget;
  defaultValue: boolean | null;
}

export const BOOLEAN_PRESETS: Record<Exclude<BooleanPreset, 'CUSTOM'>, [string, string]> = {
  YES_NO: ['Oui', 'Non'],
  TRUE_FALSE: ['Vrai', 'Faux'],
};

/**
 * RANGE (specs_fonc §4.7) : curseur sur une échelle (intervalle régulier ou valeurs personnalisées), libellés d'extrémités, suffixe d'affichage
 * (« /10 », « % »), valeur de départ comptée comme réponse ou `null` (le participant doit déplacer le curseur).
 */
export interface RangeConfig {
  min: number;
  max: number;
  step: number;
  minLabel?: string;
  maxLabel?: string;
  unit?: string;
  defaultValue: number | null;
  /** Valeurs personnalisées (ex. 2, 4, 6, 22, 40, 79), triées : remplacent l'intervalle régulier min / max / pas. */
  values?: number[];
}

export interface QuestionConfigByType {
  TEXT: TextConfig;
  NUMBER: NumberConfig;
  CHOICE: ChoiceConfig;
  DATE: DateConfig;
  FILE: FileConfig;
  BOOLEAN: BooleanConfig;
  RANGE: RangeConfig;
}

export type QuestionConfig = QuestionConfigByType[QuestionType];

/** Affichage conditionnel (specs_fonc §3.5, specs_tech §9.3). */
/**
 * Sources possibles : Oui / Non (BOOLEAN), choix unique (CHOICE radio ou liste), Chiffres (NUMBER), Échelle (RANGE).
 * Comparaisons (<, ≤, >, ≥) pour NUMBER et RANGE seulement ; IN pour CHOICE seulement.
 */
export type ConditionOperator = 'EQUALS' | 'NOT_EQUALS' | 'IN' | 'LESS_THAN' | 'LESS_OR_EQUAL' | 'GREATER_THAN' | 'GREATER_OR_EQUAL';

export interface DisplayCondition {
  /** Question du même questionnaire, placée avant. */
  sourceQuestionId: string;
  operator: ConditionOperator;
  /** Identifiant d'option (ou liste pour IN), booléen (Oui / Non) ou nombre (Chiffres, Échelle). */
  value: string | boolean | number | string[];
}

export interface Question {
  id: Uuid;
  sectionId: Uuid | null;
  type: QuestionType;
  label: string;
  helpTitle: string | null;
  helpText: string | null;
  required: boolean;
  position: number;
  config: QuestionConfig;
  /** Absent ou null : question toujours affichée. */
  displayCondition?: DisplayCondition | null;
}

export interface Section {
  id: Uuid;
  title: string;
  description: string | null;
  position: number;
}

export interface RecipientCounters {
  total: number;
  valid: number;
  pending: number;
  sent: number;
  opened: number;
  answered: number;
  bounced: number;
}

export interface SurveySummary {
  id: Uuid;
  title: string;
  description: string | null;
  status: SurveyStatus;
  expiresAt: IsoDate | null;
  activatedAt: IsoDate | null;
  closedAt: IsoDate | null;
  closeReason: CloseReason | null;
  createdAt: IsoDate;
  updatedAt: IsoDate;
  questionsCount: number;
}

/** Affichage des questions côté participant : toutes ensemble ou une par écran (indépendant des sections). */
export type DisplayMode = 'LIST' | 'ONE_BY_ONE';

export const DISPLAY_MODE_LABELS: Record<DisplayMode, string> = {
  LIST: 'Liste',
  ONE_BY_ONE: 'Question par question',
};

export const DISPLAY_MODE_ICONS: Record<DisplayMode, string> = {
  LIST: 'format-list-bulleted',
  ONE_BY_ONE: 'text-box-outline',
};

export interface Survey extends SurveySummary {
  validatedAt: IsoDate | null;
  reminderSentAt: IsoDate | null;
  remindersCount: number;
  displayMode: DisplayMode;
  /** Affiche au participant la durée estimée et le nombre de questions. */
  showDuration: boolean;
  stepper: boolean;
  sections: Section[];
  questions: Question[];
}

export interface SurveyResponse {
  survey: Survey;
  recipients: RecipientCounters;
}

export interface SurveyListItem {
  survey: SurveySummary;
  recipients: RecipientCounters;
}

export interface PageMeta {
  page: number;
  limit: number;
  total: number;
  pages: number;
}

export interface SurveyListResponse {
  items: SurveyListItem[];
  meta: PageMeta;
  counts: Record<SurveyStatus, number>;
}

export interface ValidationIssue {
  scope: 'survey' | 'section' | 'question';
  id: Uuid | null;
  position: number | null;
  message: string;
}

export interface PlatformConfig {
  uploadMaxSizeMb: number;
  fileFormats: Array<{ mimeType: string; label: string; extension: string }>;
}

export const STATUS_LABELS: Record<SurveyStatus, string> = {
  DRAFT: 'Brouillon',
  VALIDATED: 'Validé',
  ACTIVE: 'En cours',
  CLOSED: 'Clôturé',
};

export const STATUS_BADGES: Record<SurveyStatus, 'neutral' | 'info' | 'success' | 'secondary'> = {
  DRAFT: 'neutral',
  VALIDATED: 'info',
  ACTIVE: 'success',
  CLOSED: 'secondary',
};

export const CLOSE_REASON_LABELS: Record<CloseReason, string> = {
  ALL_ANSWERED: 'Tous les destinataires ont répondu',
  DEADLINE_REACHED: 'Date limite atteinte',
  MANUAL: 'Clôture manuelle',
};

export const QUESTION_TYPE_LABELS: Record<QuestionType, string> = {
  TEXT: 'Zone de texte',
  NUMBER: 'Chiffres',
  CHOICE: 'Choix',
  DATE: 'Date',
  FILE: 'Fichier',
  BOOLEAN: 'Oui / Non',
  RANGE: 'Échelle',
};

export const QUESTION_TYPE_ICONS: Record<QuestionType, string> = {
  TEXT: 'text',
  NUMBER: 'format-list-numbered',
  CHOICE: 'format-list-checks',
  DATE: 'calendar',
  FILE: 'paperclip',
  BOOLEAN: 'check-circle-outline',
  RANGE: 'tune-variant',
};

/** Valeur affichée d'une échelle : « 8/10 », « 75 % », « 3 € ». Un suffixe commençant par « / » est collé. */
export const formatRangeValue = (value: number, unit?: string): string => {
  const number = value.toLocaleString('fr-FR', { maximumFractionDigits: 6 });
  if (!unit) return number;
  return unit.startsWith('/') ? `${number}${unit}` : `${number}\u00A0${unit.trim()}`;
};
