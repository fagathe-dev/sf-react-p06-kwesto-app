import type { FileTransport } from '@/pages/participant/AnswerField';
import { SurveyRunner } from '@/pages/participant/SurveyRunner';
import { DISPLAY_MODE_ICONS, DISPLAY_MODE_LABELS, type Asset, type ChoiceConfig, type DisplayMode, type PlatformConfig, type PublicSurvey, type Question } from '@/types';
import { uuid } from '@/utils/format';
import { Icon } from 'core-react/icons';
import { Button, SegmentedControl } from 'core-react/ui';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { styled } from 'styled-components';

interface SurveyPreviewProps {
  survey: PublicSurvey;
  platform: PlatformConfig | null;
  onClose: () => void;
}

const FALLBACK_PLATFORM: PlatformConfig = { uploadMaxSizeMb: 10, fileFormats: [] };
const MODES: DisplayMode[] = ['LIST', 'ONE_BY_ONE'];

/** Fichiers de l'aperçu : rien n'est envoyé au serveur, le fichier est seulement listé. */
const previewFiles: FileTransport = {
  upload: (_questionId, file, onProgress) => {
    onProgress(100);
    const asset: Asset = {
      id: uuid(),
      originalName: file.name,
      mimeType: file.type,
      extension: file.name.split('.').pop() ?? '',
      size: file.size,
      createdAt: new Date().toISOString(),
      recipientEmail: '',
      image: file.type.startsWith('image/'),
    };
    return Promise.resolve(asset);
  },
  remove: () => Promise.resolve(),
};

/** Intitulés vides rendus visibles : l'aperçu sert justement à les repérer. */
const withPlaceholders = (survey: PublicSurvey): PublicSurvey => ({
  ...survey,
  title: survey.title.trim() || 'Questionnaire sans titre',
  sections: survey.sections.map((section, index) => ({ ...section, title: section.title.trim() || `Section ${index + 1} (sans titre)` })),
  questions: survey.questions.map((question): Question => {
    const label = question.label.trim() || '(Question sans intitulé)';
    if (question.type !== 'CHOICE') return { ...question, label };
    const config = question.config as ChoiceConfig;
    return { ...question, label, config: { ...config, options: config.options.map((o, i) => ({ ...o, label: o.label.trim() || `(Option ${i + 1} sans libellé)` })) } };
  }),
});

/**
 * Aperçu plein écran du formulaire participant, construit depuis l'état courant du builder.
 * Même parcours que le participant (SurveyRunner) ; contrôles de saisie désactivés par défaut pour
 * enchaîner « Suivant » ; aucune réponse enregistrée.
 */
export const SurveyPreview = ({ survey: raw, platform, onClose }: SurveyPreviewProps) => {
  const survey = useMemo(() => withPlaceholders(raw), [raw]);
  const [mode, setMode] = useState<DisplayMode>(raw.displayMode);
  const [checkAnswers, setCheckAnswers] = useState(false);
  const [finished, setFinished] = useState(false);
  // Recommencer ou changer de mode repart d'un parcours vierge
  const [run, setRun] = useState(0);
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement>(null);
  const endRef = useRef<HTMLElement>(null);
  const closeRef = useRef(onClose);

  useEffect(() => {
    closeRef.current = onClose;
  });

  // Fenêtre modale : le reste de l'application est inerte, le défilement de la page est bloqué, le focus revient à l'ouvreur
  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    const root = document.getElementById('root');
    const overflow = document.body.style.overflow;
    root?.setAttribute('inert', '');
    document.body.style.overflow = 'hidden';
    dialogRef.current?.focus();

    // Échap ferme l'aperçu même si le focus est retombé sur le document (bouton démonté)
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !event.defaultPrevented) closeRef.current();
    };
    document.addEventListener('keydown', onKeyDown);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      root?.removeAttribute('inert');
      document.body.style.overflow = overflow;
      opener?.focus?.();
    };
  }, []);

  const restart = () => {
    setFinished(false);
    setRun((n) => n + 1);
  };

  return createPortal(
    <Overlay ref={dialogRef} role="dialog" aria-modal="true" aria-labelledby={titleId} tabIndex={-1}>
      <Toolbar>
        <ToolbarTitle id={titleId}>
          <Icon name="eye-outline" size={20} /> Aperçu
          <small>Vue participant · aucune réponse n'est enregistrée</small>
        </ToolbarTitle>
        <ToolbarActions>
          <SegmentedControl<DisplayMode>
            aria-label="Affichage des questions"
            size="sm"
            value={mode}
            onChange={(next) => {
              setMode(next);
              restart();
            }}
            options={MODES.map((value) => ({ value, label: DISPLAY_MODE_LABELS[value], icon: DISPLAY_MODE_ICONS[value] }))}
          />
          <CheckLabel>
            <input type="checkbox" checked={checkAnswers} onChange={(e) => setCheckAnswers(e.target.checked)} /> Appliquer les contrôles
          </CheckLabel>
          <Button $variant="ghost" onClick={onClose}>
            <Icon name="close" size={18} /> Fermer l'aperçu
          </Button>
        </ToolbarActions>
      </Toolbar>

      <Scroll>
        {finished ? (
          <End ref={endRef} role="status" tabIndex={-1}>
            <EndIcon>
              <Icon name="check-circle-outline" size={40} />
            </EndIcon>
            <h2>Fin de l'aperçu</h2>
            <p>Ici, le participant envoie ses réponses et voit le message de remerciement. Rien n'a été enregistré.</p>
            <EndActions>
              <Button $variant="outline" onClick={restart}>
                <Icon name="history" size={18} /> Recommencer
              </Button>
              <Button onClick={onClose}>Fermer l'aperçu</Button>
            </EndActions>
          </End>
        ) : (
          <SurveyRunner
            key={`${mode}-${run}`}
            survey={survey}
            mode={mode}
            platform={platform ?? FALLBACK_PLATFORM}
            files={previewFiles}
            validate={checkAnswers}
            navigableStepper
            finishLabel="Terminer l'aperçu"
            finishIcon="flag-outline"
            onFinish={() => {
              setFinished(true);
              requestAnimationFrame(() => endRef.current?.focus());
            }}
          />
        )}
      </Scroll>
    </Overlay>,
    document.body
  );
};

const Overlay = styled.div`
  position: fixed;
  inset: 0;
  z-index: 1000;
  display: flex;
  flex-direction: column;
  background: ${({ theme }) => theme.colors.background.body};

  &:focus {
    outline: none;
  }
`;

const Toolbar = styled.header`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: ${({ theme }) => theme.spacing[3]};
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[4]}`};
  border-bottom: 1px solid ${({ theme }) => theme.colors.border.default};
  background: ${({ theme }) => theme.colors.background.surface};
  box-shadow: ${({ theme }) => theme.shadows.sm};
`;

const ToolbarTitle = styled.h2`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  margin: 0;
  font-size: 1rem;

  small {
    font-size: 0.8rem;
    font-weight: 400;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;

const ToolbarActions = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
`;

const CheckLabel = styled.label`
  display: inline-flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1.5]};
  font-size: 0.85rem;
  cursor: pointer;

  input {
    accent-color: ${({ theme }) => theme.colors.primary.base};
  }
`;

const Scroll = styled.div`
  flex: 1;
  overflow-y: auto;
  padding-bottom: ${({ theme }) => theme.spacing[7]};
`;

const End = styled.section`
  margin: ${({ theme }) => theme.spacing[7]} auto;
  max-width: 520px;
  padding: 0 ${({ theme }) => theme.spacing[4]};
  text-align: center;

  &:focus {
    outline: none;
  }

  h2 {
    margin: ${({ theme }) => theme.spacing[4]} 0 ${({ theme }) => theme.spacing[2]};
    font-size: 1.5rem;
  }

  p {
    margin: 0;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;

const EndIcon = styled.div`
  display: inline-grid;
  place-items: center;
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: ${({ theme }) => theme.colors.success.subtle};
  color: ${({ theme }) => theme.colors.success.base};
`;

const EndActions = styled.div`
  display: flex;
  justify-content: center;
  gap: ${({ theme }) => theme.spacing[3]};
  margin-top: ${({ theme }) => theme.spacing[5]};
`;
