import type { IsoDate, PageMeta, RecipientCounters, Uuid } from './survey.types';

export type RecipientStatus = 'PENDING' | 'SENT' | 'OPENED' | 'ANSWERED' | 'BOUNCED';
export type DeviceType = 'desktop' | 'mobile' | 'tablet';

export interface Recipient {
  id: Uuid;
  email: string;
  status: RecipientStatus;
  sentAt: IsoDate | null;
  openedAt: IsoDate | null;
  submittedAt: IsoDate | null;
  reminderSentAt: IsoDate | null;
  bouncedAt: IsoDate | null;
  sendAttempts: number;
  lastError: string | null;
  ipAddress: string | null;
  userAgentRaw: string | null;
  browserName: string | null;
  browserVersion: string | null;
  osName: string | null;
  deviceType: DeviceType | null;
  completionSeconds: number | null;
  createdAt: IsoDate;
}

export interface RecipientListResponse {
  items: Recipient[];
  meta: PageMeta;
  counters: RecipientCounters;
}

export interface AddRecipientsResponse {
  added: number;
  alreadyPresent: number;
  duplicates: number;
  invalid: string[];
  overLimit: number;
  counters: RecipientCounters;
}

export const RECIPIENT_STATUS_LABELS: Record<RecipientStatus, string> = {
  PENDING: 'En file d\'attente',
  SENT: 'Envoyé',
  OPENED: 'Ouvert',
  ANSWERED: 'Répondu',
  BOUNCED: 'Adresse rejetée',
};

export const RECIPIENT_STATUS_BADGES: Record<RecipientStatus, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  PENDING: 'neutral',
  SENT: 'info',
  OPENED: 'warning',
  ANSWERED: 'success',
  BOUNCED: 'danger',
};

export const DEVICE_LABELS: Record<DeviceType, string> = {
  desktop: 'Ordinateur',
  mobile: 'Mobile',
  tablet: 'Tablette',
};
