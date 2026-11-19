import { duplicateSurvey } from '@/services/api';
import { errorMessage } from '@/services/errors';
import { Icon } from 'core-react/icons';
import { Button, useToast } from 'core-react/ui';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';

interface DuplicateSurveyButtonProps {
  surveyId: string;
  title: string;
  size?: 'sm' | 'base';
  /** Avant la copie : enregistre les modifications en attente du builder. */
  beforeDuplicate?: () => Promise<unknown>;
}

/**
 * Duplique un questionnaire (quel que soit son état) : nouveau brouillon avec les mêmes options, sections,
 * questions et conditions, sans destinataires ni réponses ; ouvre le builder sur la copie.
 */
export const DuplicateSurveyButton = ({ surveyId, title, size = 'base', beforeDuplicate }: DuplicateSurveyButtonProps) => {
  const navigate = useNavigate();
  const { success, error: toastError } = useToast();
  const [busy, setBusy] = useState(false);

  const duplicate = async () => {
    setBusy(true);
    try {
      await beforeDuplicate?.();
      const { survey } = await duplicateSurvey(surveyId);
      success(`« ${survey.title} » est un nouveau brouillon modifiable.`, 'Questionnaire dupliqué');
      navigate(`/surveys/${survey.id}`);
    } catch (err) {
      toastError(errorMessage(err), 'Duplication impossible');
      setBusy(false);
    }
  };

  return (
    <Button $variant="ghost" $size={size} $loading={busy} onClick={() => void duplicate()} aria-label={`Dupliquer « ${title} »`} title="Créer un brouillon à partir de ce questionnaire">
      <Icon name="content-copy" size={size === 'sm' ? 16 : 18} /> Dupliquer
    </Button>
  );
};
