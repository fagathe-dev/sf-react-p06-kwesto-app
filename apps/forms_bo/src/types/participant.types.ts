import type { Asset } from './stats.types';
import type { DisplayMode, IsoDate, PlatformConfig, Question, Section, Uuid } from './survey.types';

export type ParticipantState = 'OPEN' | 'INVALID' | 'CLOSED' | 'ANSWERED';

export interface PublicSurvey {
  id: Uuid;
  title: string;
  description: string | null;
  expiresAt: IsoDate | null;
  questionsCount: number;
  displayMode: DisplayMode;
  showDuration: boolean;
  stepper: boolean;
  sections: Section[];
  questions: Question[];
}

/** Réponses en cours d'une visite précédente (specs_fonc §6.5). */
export interface ParticipantDraft {
  answers: Answers;
  /** Première question de l'écran où le participant s'est arrêté. */
  questionId: Uuid | null;
  savedAt: IsoDate;
}

export interface OpenResponse {
  state: 'OPEN';
  survey: PublicSurvey;
  pendingAssets: Record<Uuid, Asset[]>;
  /** null : première visite, ou rien n'avait été saisi. */
  draft?: ParticipantDraft | null;
  config: PlatformConfig;
}

export interface BlockedResponse {
  state: Exclude<ParticipantState, 'OPEN'>;
  message: string;
  survey?: { title: string };
  submittedAt?: IsoDate | null;
}

/** Valeur saisie : texte, nombre saisi (chaîne), identifiants d'options, date YYYY-MM-DD, identifiants de fichiers. */
export type AnswerValue = string | string[];
export type Answers = Record<Uuid, AnswerValue>;
