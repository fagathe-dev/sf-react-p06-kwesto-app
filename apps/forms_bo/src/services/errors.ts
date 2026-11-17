import { ApiError } from 'core-ts';

interface ErrorBody {
  message?: string;
  title?: string;
  violations?: Record<string, string>;
  errors?: unknown;
}

const body = (error: unknown): ErrorBody | null =>
  error instanceof ApiError && error.data && typeof error.data === 'object' ? (error.data as ErrorBody) : null;

/** Message lisible d'une erreur API (violations de validation comprises). */
export const errorMessage = (error: unknown, fallback = 'Une erreur est survenue.'): string => {
  const data = body(error);

  if (data?.violations) {
    const first = Object.values(data.violations)[0];
    if (first) return first;
  }

  if (data?.message) return data.message;
  if (error instanceof ApiError && error.isNetworkError()) return 'Serveur injoignable. Vérifiez votre connexion.';
  if (error instanceof Error && error.message) return error.message;

  return fallback;
};

/** Violations { champ: message } renvoyées par ResponseTrait::sendViolations (core-php). */
export const violationsOf = (error: unknown): Record<string, string> => body(error)?.violations ?? {};

export const statusOf = (error: unknown): number | null => (error instanceof ApiError ? error.status : null);

export const dataOf = <T>(error: unknown): T | null => (error instanceof ApiError ? (error.data as T) : null);
