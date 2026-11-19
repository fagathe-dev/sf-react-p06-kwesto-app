import { encodePassword } from '@/utils/secret';
import type {
  AddRecipientsResponse,
  Asset,
  AuthResponse,
  DisplayCondition,
  DisplayMode,
  EmailPreview,
  LogSearchResponse,
  Paginated,
  PlatformConfig,
  RecipientListResponse,
  RecipientStatus,
  Section,
  StatsResponse,
  SurveyListResponse,
  SurveyResponse,
  SurveyStatus,
  User,
  Verbatim,
} from '@/types';
import { router as buildUrl } from 'core-ts';
import { downloadFile, http } from './http';

/** Appels à forms_api : un verbe explicite par endpoint (aucun fetch direct dans les composants). */

const q = (path: string, params: Record<string, string | number | boolean | undefined | null>): string => {
  const clean: Record<string, string | number | boolean> = {};
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') clean[key] = value;
  }

  return buildUrl(path, clean);
};

// --- Authentification
export const login = (email: string, password: string) =>
  // Mot de passe encodé en base64 (règle commune à toute route qui reçoit un mot de passe)
  http.post<AuthResponse>('/api/auth/login', { email, password: encodePassword(password) }, { anonymous: true });
export const fetchMe = () => http.get<User>('/api/auth/me');

// --- Configuration de la plateforme
export const fetchPlatformConfig = () => http.get<PlatformConfig>('/api/public/config', { anonymous: true });

// --- Module 1 : questionnaires
export const fetchSurveys = (params: { page?: number; status?: SurveyStatus | ''; q?: string }) =>
  http.get<SurveyListResponse>(q('/api/admin/surveys', params));
export const fetchSurvey = (id: string) => http.get<SurveyResponse>(`/api/admin/surveys/${id}`);
export const createSurvey = (title: string, description?: string) =>
  http.post<SurveyResponse>('/api/admin/surveys', { title, description });
export const updateSurvey = (id: string, data: { title?: string; description?: string | null; displayMode?: DisplayMode; showDuration?: boolean }) =>
  http.patch<SurveyResponse>(`/api/admin/surveys/${id}`, data);
export const deleteSurvey = (id: string) => http.delete<null>(`/api/admin/surveys/${id}`);
export const duplicateSurvey = (id: string) => http.post<SurveyResponse>(`/api/admin/surveys/${id}/duplicate`);
export const validateSurvey = (id: string) => http.post<SurveyResponse>(`/api/admin/surveys/${id}/validate`);

export interface StructurePayload {
  sections: Array<Pick<Section, 'id' | 'title' | 'description' | 'displayCondition'>>;
  questions: Array<{
    id: string;
    sectionId: string | null;
    type: string;
    label: string;
    helpTitle: string | null;
    helpText: string | null;
    required: boolean;
    config: unknown;
    displayCondition: DisplayCondition | null;
  }>;
}
export const saveStructure = (id: string, payload: StructurePayload) =>
  http.put<SurveyResponse>(`/api/admin/surveys/${id}/structure`, payload);

// --- Module 2 : diffusion
export const fetchRecipients = (id: string, params: { page?: number; status?: RecipientStatus | ''; q?: string }) =>
  http.get<RecipientListResponse>(q(`/api/admin/surveys/${id}/recipients`, params));
export const addRecipients = (id: string, emails: string) =>
  http.post<AddRecipientsResponse>(`/api/admin/surveys/${id}/recipients`, { emails });
export const importRecipientsCsv = (id: string, file: File) => {
  const form = new FormData();
  form.append('file', file);
  return http.post<AddRecipientsResponse>(`/api/admin/surveys/${id}/recipients`, form);
};
export const removeRecipients = (id: string, ids: string[] | 'all') =>
  http.delete<{ removed: number }>(`/api/admin/surveys/${id}/recipients`, ids === 'all' ? { all: true } : { ids });
export const launchSurvey = (id: string, expiresAt: string) =>
  http.post<SurveyResponse>(`/api/admin/surveys/${id}/launch`, { expiresAt });
export const closeSurvey = (id: string) => http.post<SurveyResponse>(`/api/admin/surveys/${id}/close`);

// --- Module 4 : statistiques et exports
export const fetchStats = (id: string, refresh = false) =>
  http.get<StatsResponse>(q(`/api/admin/surveys/${id}/stats`, { refresh: refresh ? 1 : undefined }));
export const fetchVerbatims = (id: string, questionId: string, params: { page?: number; q?: string }) =>
  http.get<Paginated<Verbatim>>(q(`/api/admin/surveys/${id}/stats/questions/${questionId}/verbatims`, params));
export const fetchQuestionFiles = (id: string, questionId: string, page = 1) =>
  http.get<Paginated<Asset>>(q(`/api/admin/surveys/${id}/stats/questions/${questionId}/files`, { page }));
export const downloadExport = (id: string) => downloadFile(`/api/admin/surveys/${id}/export`, 'reponses.csv');
export const downloadArchive = (id: string) => downloadFile(`/api/admin/surveys/${id}/archive`, 'fichiers.zip');
export const downloadAsset = (id: string, asset: Asset) =>
  downloadFile(`/api/admin/surveys/${id}/assets/${asset.id}`, asset.originalName);
export const assetPath = (id: string, assetId: string) => `/api/admin/surveys/${id}/assets/${assetId}?inline=1`;

// --- Recherche
export interface SearchResponse {
  query: string;
  surveys: SurveyListResponse['items'][number]['survey'][];
  questions: Array<{ id: string; type: string; label: string; position: number; surveyId: string; surveyTitle: string; helpText: string | null }>;
  recipients: Array<{ id: string; email: string; status: RecipientStatus; surveyId: string; surveyTitle: string }>;
  verbatims: Array<{ id: string; text: string; surveyId: string; surveyTitle: string; questionId: string; questionLabel: string }>;
}
export const search = (query: string) => http.get<SearchResponse>(q('/api/admin/search', { q: query }));

// --- Espace Administration
export const fetchEmailPreviews = () => http.get<EmailPreview[]>('/api/admin/platform/emails');
export const renderEmailPreview = (slug: string, data: Record<string, string>) =>
  http.post<{ subject: string; html: string }>(`/api/admin/platform/emails/${slug}`, { data });
export const sendTestEmail = (slug: string, data: Record<string, string>) =>
  http.post<{ message: string }>(`/api/admin/platform/emails/${slug}/test`, { data });
export const fetchLogs = (params: { channel?: string; level?: string; from?: string; to?: string; q?: string; page?: number }) =>
  http.get<LogSearchResponse>(q('/api/admin/platform/logs', params));
