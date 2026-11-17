import type { Answers, Asset, BlockedResponse, OpenResponse } from '@/types';
import { ApiError } from 'core-ts';
import { http } from './http';

/** Formulaire répondant : appels anonymes, le jeton du lien fait office d'accès. */

const base = (token: string) => `/api/public/surveys/${encodeURIComponent(token)}`;

/**
 * Ouverture du lien. Les écrans « lien invalide », « clôturé » et « déjà répondu » arrivent
 * avec un statut d'erreur HTTP : ils sont renvoyés comme un état, pas comme une exception.
 */
export const openSurvey = async (token: string): Promise<OpenResponse | BlockedResponse> => {
  try {
    return await http.get<OpenResponse>(base(token), { anonymous: true });
  } catch (error) {
    if (error instanceof ApiError && error.data && typeof error.data === 'object' && 'state' in error.data) {
      return error.data as BlockedResponse;
    }
    throw error;
  }
};

export const submitAnswers = (token: string, answers: Answers) =>
  http.post<{ state: 'ANSWERED'; message: string; submittedAt: string }>(`${base(token)}/submit`, { answers }, { anonymous: true });

export const uploadAsset = (token: string, questionId: string, file: File, onProgress?: (percent: number) => void) => {
  const form = new FormData();
  form.append('file', file);

  return http.post<Asset>(`${base(token)}/questions/${questionId}/files`, form, {
    anonymous: true,
    timeout: 10 * 60_000,
    onUploadProgress: (event: { loaded: number; total?: number }) => {
      if (onProgress && event.total) onProgress(Math.round((event.loaded / event.total) * 100));
    },
  });
};

export const deleteAsset = (token: string, assetId: string) =>
  http.delete<null>(`${base(token)}/files/${assetId}`, undefined, { anonymous: true });

/**
 * Brouillon (specs_fonc §6.5) : réponses en cours, enregistrées au fil de la saisie, et première question
 * de l'écran courant pour reprendre au même endroit.
 */
export const saveDraft = (token: string, answers: Answers, questionId: string | null) =>
  http.put<{ savedAt: string | null }>(`${base(token)}/draft`, { answers, questionId }, { anonymous: true });

/** « Recommencer » : brouillon effacé et fichiers déjà déposés supprimés. */
export const discardDraft = (token: string) => http.delete<null>(`${base(token)}/draft`, undefined, { anonymous: true });
