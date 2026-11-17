import { fetchPlatformConfig } from '@/services/api';
import type { PlatformConfig } from '@/types';
import { create } from 'zustand';

interface ConfigState {
  config: PlatformConfig | null;
  load: () => Promise<PlatformConfig>;
}

/**
 * Configuration de la plateforme (limite d'upload, formats), lue une fois depuis forms_api :
 * la limite n'est jamais dupliquée dans une variable VITE_* (specs_tech §8.5).
 */
export const useConfigStore = create<ConfigState>((set, get) => ({
  config: null,
  load: async () => {
    const current = get().config;
    if (current) return current;

    const config = await fetchPlatformConfig();
    set({ config });
    return config;
  },
}));
