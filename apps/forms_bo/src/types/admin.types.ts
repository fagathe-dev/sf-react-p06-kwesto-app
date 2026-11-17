import type { PageMeta } from './survey.types';

export interface EmailPreviewField {
  name: string;
  label: string;
  type: 'text' | 'email' | 'number' | 'textarea' | 'datetime-local';
  value: string;
}

export interface EmailPreview {
  slug: string;
  code: string;
  label: string;
  description: string;
  audience: string;
  subject: string;
  from: Array<{ name: string | null; email: string }>;
  fields: EmailPreviewField[];
}

export type LogChannel = 'mailer' | 'cron' | 'app' | 'security';
export type LogLevel = 'INFO' | 'WARNING' | 'ERROR' | 'CRITICAL';

export interface LogEntry {
  id: string;
  channel: LogChannel;
  source: string;
  level: LogLevel;
  timestamp: string | null;
  message: string;
  action: string;
  content: Record<string, unknown>;
  context: Record<string, unknown>;
}

export interface LogSearchResponse {
  items: LogEntry[];
  meta: PageMeta;
  filters: { channels: LogChannel[]; levels: LogLevel[]; from: string; to: string };
  levelCounts: Record<LogLevel, number>;
}
