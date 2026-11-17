import { errorMessage } from '@/services/errors';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface AsyncState<T> {
  data: T | null;
  error: string | null;
  isLoading: boolean;
  reload: () => Promise<void>;
  setData: (data: T | null) => void;
}

/**
 * Chargement d'une ressource : relancé quand `deps` change, réponses obsolètes ignorées.
 */
export const useAsync = <T>(loader: () => Promise<T>, deps: readonly unknown[]): AsyncState<T> => {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const loaderRef = useRef(loader);
  const requestId = useRef(0);

  useEffect(() => {
    loaderRef.current = loader;
  });

  const reload = useCallback(async () => {
    const id = ++requestId.current;
    // Les mises à jour d'état ont lieu après la reprise asynchrone, jamais pendant l'effet
    await Promise.resolve();
    if (id !== requestId.current) return;

    setIsLoading(true);
    setError(null);

    try {
      const result = await loaderRef.current();
      if (id === requestId.current) setData(result);
    } catch (err) {
      if (id === requestId.current) setError(errorMessage(err));
    } finally {
      if (id === requestId.current) setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void reload();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);

  return { data, error, isLoading, reload, setData };
};
