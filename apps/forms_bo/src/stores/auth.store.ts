import { fetchMe } from '@/services/api';
import { setUnauthorizedHandler } from '@/services/http';
import { isTokenExpired, tokenStorage } from '@/services/tokenStorage';
import type { User } from '@/types';
import { create } from 'zustand';

interface AuthState {
  token: string | null;
  user: User | null;
  isLoading: boolean;
  setSession: (token: string, user: User) => void;
  logout: () => void;
  restore: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  token: tokenStorage.get(),
  user: null,
  isLoading: true,

  setSession: (token, user) => {
    tokenStorage.set(token);
    set({ token, user, isLoading: false });
  },

  logout: () => {
    tokenStorage.clear();
    set({ token: null, user: null, isLoading: false });
  },

  /** Restaure la session au chargement : jeton présent et valide → profil rechargé depuis l'API. */
  restore: async () => {
    const { token, logout } = get();

    if (!token || isTokenExpired(token)) {
      logout();
      return;
    }

    try {
      set({ isLoading: true });
      set({ user: await fetchMe(), isLoading: false });
    } catch {
      logout();
    }
  },
}));

// Jeton expiré ou révoqué en cours de session : retour à l'écran de connexion
setUnauthorizedHandler(() => useAuthStore.getState().logout());

export const isAdmin = (user: User | null): boolean => user?.roles.includes('ROLE_ADMIN') ?? false;
