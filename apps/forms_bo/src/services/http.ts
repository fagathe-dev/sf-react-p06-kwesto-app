import { ApiError, fetchAPI, type AuthenticatedRequestOptions } from 'core-ts';
import { tokenStorage } from './tokenStorage';

/**
 * Client HTTP de forms_api, basé sur fetchAPI de core-ts (retries, timeout, ApiError).
 * Ajoute le préfixe VITE_API_URL et l'en-tête Authorization: Bearer <JWT>.
 */
const BASE_URL = (import.meta.env.VITE_API_URL || 'http://localhost:8000').replace(/\/+$/, '');

type Options = Omit<AuthenticatedRequestOptions, 'method' | 'body'> & { anonymous?: boolean };

/** Appelé quand l'API répond 401 sur une route authentifiée (jeton expiré ou révoqué). */
let onUnauthorized: () => void = () => undefined;
export const setUnauthorizedHandler = (handler: () => void): void => {
  onUnauthorized = handler;
};

export const apiUrl = (path: string): string => `${BASE_URL}${path.startsWith('/') ? '' : '/'}${path}`;

const request = async <T>(method: string, path: string, body?: unknown, options: Options = {}): Promise<T> => {
  const { anonymous = false, headers, ...rest } = options;
  const token = anonymous ? null : tokenStorage.get();

  try {
    const response = await fetchAPI<T>(apiUrl(path), {
      retries: 0,
      timeout: 60_000,
      ...rest,
      method,
      body: body as BodyInit | undefined,
      headers: {
        ...(headers as Record<string, string> | undefined),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    });

    return response.data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 401 && !anonymous) {
      onUnauthorized();
    }
    throw error;
  }
};

export const http = {
  get: <T>(path: string, options?: Options) => request<T>('GET', path, undefined, options),
  post: <T>(path: string, body?: unknown, options?: Options) => request<T>('POST', path, body ?? {}, options),
  put: <T>(path: string, body?: unknown, options?: Options) => request<T>('PUT', path, body ?? {}, options),
  patch: <T>(path: string, body?: unknown, options?: Options) => request<T>('PATCH', path, body ?? {}, options),
  delete: <T>(path: string, body?: unknown, options?: Options) => request<T>('DELETE', path, body, options),
};

/**
 * Téléchargement d'un fichier protégé (export CSV, archive ZIP, pièce jointe) : le jeton ne peut pas
 * passer dans un lien, le fichier est donc récupéré en Blob puis proposé au navigateur.
 */
export const downloadFile = async (path: string, fallbackName: string): Promise<void> => {
  const token = tokenStorage.get();
  const response = await fetch(apiUrl(path), { headers: token ? { Authorization: `Bearer ${token}` } : {} });

  if (!response.ok) {
    const data = (await response.json().catch(() => ({}))) as { message?: string };
    throw new Error(data.message ?? 'Le téléchargement a échoué.');
  }

  const disposition = response.headers.get('Content-Disposition') ?? '';
  const match = /filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i.exec(disposition);
  const fileName = match ? decodeURIComponent(match[1] ?? match[2] ?? fallbackName) : fallbackName;

  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  document.body.appendChild(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
};

/** Image protégée affichée dans la galerie : URL blob à révoquer après usage. */
export const fetchObjectUrl = async (path: string): Promise<string> => {
  const token = tokenStorage.get();
  const response = await fetch(apiUrl(path), { headers: token ? { Authorization: `Bearer ${token}` } : {} });
  if (!response.ok) {
    throw new Error('Aperçu indisponible.');
  }

  return URL.createObjectURL(await response.blob());
};
