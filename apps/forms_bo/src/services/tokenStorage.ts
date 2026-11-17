import { StorageService } from 'core-ts';

const TOKEN_KEY = 'kwesto.token';
const storage = new StorageService('local');

/** Jeton JWT du back-office, persisté localement (core-ts StorageService). */
export const tokenStorage = {
  get: (): string | null => {
    const value = storage.get(TOKEN_KEY);
    return typeof value === 'string' && value !== '' ? value : null;
  },
  set: (token: string): void => {
    storage.set(TOKEN_KEY, token);
  },
  clear: (): void => {
    storage.remove(TOKEN_KEY);
  },
};

/** Le jeton est expiré si sa date d'expiration (claim exp, en secondes) est passée. */
export const isTokenExpired = (token: string): boolean => {
  try {
    const payload = token.split('.')[1];
    if (!payload) return true;
    const json = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/'))) as { exp?: number };
    return typeof json.exp !== 'number' || json.exp * 1000 <= Date.now();
  } catch {
    return true;
  }
};
