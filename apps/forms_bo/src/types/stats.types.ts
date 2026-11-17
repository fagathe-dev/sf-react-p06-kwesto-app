import type { IsoDate, PageMeta, QuestionConfig, QuestionType, SurveySummary, Uuid } from './survey.types';

export interface CountItem {
  label: string;
  count: number;
}

export interface GlobalStats {
  invited: number;
  valid: number;
  bounced: number;
  pending: number;
  sent: number;
  opened: number;
  answered: number;
  participationRate: number;
  openedNotAnsweredRate: number;
  averageCompletionSeconds: number | null;
  browsers: CountItem[];
  operatingSystems: CountItem[];
  devices: CountItem[];
}

export interface TextStats {
  count: number;
  averageLength: number | null;
}

export interface NumberStats {
  count: number;
  min: number | null;
  max: number | null;
  mean: number | null;
  median: number | null;
  standardDeviation: number | null;
  histogram: Array<{ from: number; to: number; count: number }>;
}

export interface ChoiceStats {
  count: number;
  multiple: boolean;
  options: Array<{ id: string; label: string; count: number; percent: number }>;
}

export interface DateStats {
  count: number;
  earliest: string | null;
  latest: string | null;
  median: string | null;
  granularity: 'day' | 'month';
  distribution: Array<{ period: string; count: number }>;
}

export interface FileStats {
  count: number;
  totalFiles: number;
  totalSize: number;
  formats: Array<{ extension: string; count: number; size: number }>;
}

export interface BooleanStats {
  count: number;
  trueCount: number;
  falseCount: number;
  truePercent: number;
  falsePercent: number;
  trueLabel: string;
  falseLabel: string;
}

export interface RangeStats {
  count: number;
  min: number | null;
  max: number | null;
  mean: number | null;
  median: number | null;
  scaleMin: number | null;
  scaleMax: number | null;
  minLabel?: string | null;
  maxLabel?: string | null;
  unit?: string | null;
  /** Répartition cran par cran (échelle de 21 crans au plus), sinon vide. */
  distribution: Array<{ value: number; count: number }>;
  /** Histogramme par tranches pour une échelle plus fine. */
  histogram: Array<{ from: number; to: number; count: number }>;
}

export interface QuestionStatsByType {
  TEXT: TextStats;
  NUMBER: NumberStats;
  CHOICE: ChoiceStats;
  DATE: DateStats;
  FILE: FileStats;
  BOOLEAN: BooleanStats;
  RANGE: RangeStats;
}

export interface QuestionStats<T extends QuestionType = QuestionType> {
  id: Uuid;
  position: number;
  label: string;
  type: T;
  required: boolean;
  config: QuestionConfig;
  answersCount: number;
  answerRate: number;
  stats: QuestionStatsByType[T];
}

export interface StatsResponse {
  survey: SurveySummary;
  stats: {
    global: GlobalStats;
    questions: QuestionStats[];
    computedAt: IsoDate;
    frozen: boolean;
  };
}

export interface Verbatim {
  id: Uuid;
  text: string;
  createdAt: IsoDate;
}

export interface Asset {
  id: Uuid;
  originalName: string;
  mimeType: string;
  extension: string;
  size: number;
  createdAt: IsoDate;
  recipientEmail: string;
  image: boolean;
}

export interface Paginated<T> {
  items: T[];
  meta: PageMeta;
}
