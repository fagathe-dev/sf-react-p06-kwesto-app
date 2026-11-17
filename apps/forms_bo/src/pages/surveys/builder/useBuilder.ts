import { saveStructure } from '@/services/api';
import { errorMessage } from '@/services/errors';
import type { Survey } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';
import { fromSurvey, toPayload, type BuilderState } from './builderModel';

export type SaveStatus = 'idle' | 'dirty' | 'saving' | 'saved' | 'error';

const AUTOSAVE_DELAY = 800;

/**
 * État du builder et enregistrement automatique : chaque modification (y compris un déplacement)
 * est persistée après un court délai, l'ordre reçu par l'API fait foi (specs_fonc §3.2).
 */
export const useBuilder = (survey: Survey | null, onSaved: (survey: Survey) => void) => {
  const [state, setState] = useState<BuilderState>({ sections: [], questions: [] });
  const [status, setStatus] = useState<SaveStatus>('idle');
  const [saveError, setSaveError] = useState<string | null>(null);
  const pending = useRef<BuilderState | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Glisser-déposer en cours : les états intermédiaires ne sont pas enregistrés
  const held = useRef(false);
  const surveyId = survey?.id ?? null;
  const editable = survey?.status === 'DRAFT';
  const onSavedRef = useRef(onSaved);
  const [loadedId, setLoadedId] = useState<string | null>(null);

  useEffect(() => {
    onSavedRef.current = onSaved;
  });

  // Réinitialisation quand on change de questionnaire (pas à chaque enregistrement) : état dérivé
  if (survey && survey.id !== loadedId) {
    setLoadedId(survey.id);
    setState(fromSurvey(survey));
  }

  const flush = useCallback(async () => {
    if (timer.current) {
      clearTimeout(timer.current);
      timer.current = null;
    }

    const next = pending.current;
    if (!next || !surveyId) return;

    pending.current = null;
    setStatus('saving');
    try {
      const { survey: saved } = await saveStructure(surveyId, toPayload(next));
      setSaveError(null);
      setStatus(pending.current ? 'dirty' : 'saved');
      onSavedRef.current(saved);
    } catch (error) {
      pending.current ??= next;
      setSaveError(errorMessage(error, 'Enregistrement impossible.'));
      setStatus('error');
    }
  }, [surveyId]);

  const update = useCallback(
    (updater: (current: BuilderState) => BuilderState) => {
      if (!editable) return;

      setState((current) => {
        const next = updater(current);
        pending.current = next;
        return next;
      });
      setStatus('dirty');

      if (timer.current) clearTimeout(timer.current);
      if (!held.current) timer.current = setTimeout(() => void flush(), AUTOSAVE_DELAY);
    },
    [editable, flush]
  );

  /** Suspend (true) puis reprend (false) l'enregistrement automatique, ex. pendant un glisser-déposer. */
  const hold = useCallback(
    (value: boolean) => {
      held.current = value;
      if (timer.current) clearTimeout(timer.current);
      timer.current = !value && pending.current ? setTimeout(() => void flush(), AUTOSAVE_DELAY) : null;
    },
    [flush]
  );

  // Enregistrement immédiat avant de quitter la page
  useEffect(() => {
    const beforeUnload = (event: BeforeUnloadEvent) => {
      if (pending.current) event.preventDefault();
    };
    window.addEventListener('beforeunload', beforeUnload);

    return () => {
      window.removeEventListener('beforeunload', beforeUnload);
      void flush();
    };
  }, [flush]);

  return { state, update, hold, flush, status, saveError, editable };
};
