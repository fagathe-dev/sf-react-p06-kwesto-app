import { DateFormatter, FileSizeFormatter } from 'core-ts';

/** Formats d'affichage (dates en français, poids, durées) : s'appuient sur core-ts. */

export const formatDate = (iso: string | null | undefined): string => (iso ? DateFormatter.short(iso) : '—');

export const formatDateTime = (iso: string | null | undefined): string => (iso ? DateFormatter.full(iso) : '—');

export const formatAgo = (iso: string | null | undefined): string => (iso ? DateFormatter.ago(iso) : '—');

export const formatBytes = (bytes: number | null | undefined): string => FileSizeFormatter.format(bytes ?? 0, 1);

export const formatNumber = (value: number | null | undefined, digits = 2): string =>
  value === null || value === undefined ? '—' : value.toLocaleString('fr-FR', { maximumFractionDigits: digits });

export const formatPercent = (value: number | null | undefined): string =>
  value === null || value === undefined ? '—' : `${value.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %`;

/** Durée lisible : « 2 min 05 s », « 1 h 12 min ». */
export const formatDuration = (seconds: number | null | undefined): string => {
  if (seconds === null || seconds === undefined) return '—';
  if (seconds < 60) return `${seconds} s`;

  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} min ${String(seconds % 60).padStart(2, '0')} s`;

  return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
};

/** Date ISO (ou YYYY-MM-DD) → « 13/11/2026 » sans décalage de fuseau. */
export const formatCalendarDate = (value: string | null | undefined): string => {
  if (!value) return '—';
  const [year, month, day] = value.slice(0, 10).split('-');
  return day && month && year ? `${day}/${month}/${year}` : value;
};

/** Valeur d'un <input type="datetime-local"> à partir d'une date. */
export const toDateTimeLocal = (date: Date): string => {
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

export const uuid = (): string => crypto.randomUUID();
