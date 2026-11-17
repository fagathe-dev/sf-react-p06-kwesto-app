import { deleteAsset, discardDraft, openSurvey, saveDraft, submitAnswers, uploadAsset } from '@/services/participant.api';
import { dataOf, errorMessage, statusOf, violationsOf } from '@/services/errors';
import type { Answers, BlockedResponse, OpenResponse } from '@/types';
import { formatDateTime } from '@/utils/format';
import { Icon } from 'core-react/icons';
import { Button, Loading } from 'core-react/ui';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { styled } from 'styled-components';
import type { FileTransport } from './AnswerField';
import { toPayload } from './participantModel';
import { SurveyRunner } from './SurveyRunner';

/** Dépôt réel des fichiers, rattachés au jeton d'invitation (specs_fonc §6.3). */
const participantFiles = (token: string): FileTransport => ({
  upload: (questionId, file, onProgress) => uploadAsset(token, questionId, file, onProgress),
  remove: async (assetId) => {
    await deleteAsset(token, assetId);
  },
});

type Screen =
  | { kind: 'loading' }
  | { kind: 'error'; message: string }
  | { kind: 'blocked'; response: BlockedResponse }
  | { kind: 'resume'; response: OpenResponse }
  | { kind: 'form'; response: OpenResponse }
  | { kind: 'done'; message: string; submittedAt: string };

/**
 * Formulaire répondant /s/:token (specs_fonc §6) : contrôle d'accès, barre de navigation, bandeau de
 * progression collé en haut (stepper si ≥ 2 sections), affichage en liste ou question par question,
 * soumission unique et atomique.
 */
export const ParticipantPage = () => {
  const { token = '' } = useParams();
  const [screen, setScreen] = useState<Screen>({ kind: 'loading' });

  useEffect(() => {
    document.title = 'Questionnaire — kwesto';
    let cancelled = false;

    openSurvey(token)
      .then((response) => {
        if (cancelled) return;
        if (response.state !== 'OPEN') {
          setScreen({ kind: 'blocked', response });
          return;
        }
        // Questionnaire déjà commencé : le participant choisit de reprendre ou de recommencer
        const started = response.draft && Object.keys(response.draft.answers).length > 0;
        setScreen(started ? { kind: 'resume', response } : { kind: 'form', response });
        if (response.state === 'OPEN') document.title = `${response.survey.title} — kwesto`;
      })
      .catch((error: unknown) => !cancelled && setScreen({ kind: 'error', message: errorMessage(error, 'Le questionnaire n\'a pas pu être chargé.') }));

    return () => {
      cancelled = true;
    };
  }, [token]);

  return (
    <Page>
      <NavBar>
        <Brand>
          <Icon name="list-checks" size={20} /> kwesto
        </Brand>
        {(screen.kind === 'form' || screen.kind === 'resume') && <NavTitle>{screen.response.survey.title}</NavTitle>}
      </NavBar>

      <main>
        {screen.kind === 'loading' && (
          <Center>
            <Loading size="lg" label="Chargement du questionnaire…" />
          </Center>
        )}
        {screen.kind === 'error' && <StateScreen icon="alert-circle-outline" title="Questionnaire indisponible" message={screen.message} />}
        {screen.kind === 'blocked' && <BlockedScreen response={screen.response} />}
        {screen.kind === 'done' && (
          <StateScreen icon="check-circle-outline" tone="success" title="Merci pour votre participation !" message={`${screen.message} Reçu le ${formatDateTime(screen.submittedAt)}. Un accusé de réception vous a été envoyé par e-mail.`} />
        )}
        {screen.kind === 'resume' && (
          <ResumeScreen
            token={token}
            response={screen.response}
            onResume={() => setScreen({ kind: 'form', response: screen.response })}
            // Recommencer : plus de brouillon ni de fichiers déposés
            onRestart={() => setScreen({ kind: 'form', response: { ...screen.response, draft: null, pendingAssets: {} } })}
          />
        )}
        {screen.kind === 'form' && (
          <SurveyForm
            token={token}
            response={screen.response}
            onDone={(message, submittedAt) => setScreen({ kind: 'done', message, submittedAt })}
            onBlocked={(response) => setScreen({ kind: 'blocked', response })}
          />
        )}
      </main>

      <Footer>
        <Icon name="list-checks" size={16} /> Propulsé par kwesto
      </Footer>
    </Page>
  );
};

/** Écrans d'accès refusé : lien invalide, questionnaire clôturé, réponse déjà enregistrée. */
const BlockedScreen = ({ response }: { response: BlockedResponse }) => {
  switch (response.state) {
    case 'CLOSED':
      return <StateScreen icon="lock-outline" title={response.survey?.title ?? 'Formulaire clôturé'} message={response.message} />;
    case 'ANSWERED':
      return <StateScreen icon="check-circle-outline" tone="success" title={response.survey?.title ?? 'Déjà répondu'} message={response.message} />;
    default:
      return <StateScreen icon="link-variant" title="Lien invalide" message="Ce lien n'est pas valide. Vérifiez qu'il a été copié en entier depuis l'e-mail d'invitation." />;
  }
};

interface SurveyFormProps {
  token: string;
  response: OpenResponse;
  onDone: (message: string, submittedAt: string) => void;
  onBlocked: (response: BlockedResponse) => void;
}

/** Délai de l'enregistrement automatique après la dernière saisie. */
const DRAFT_DELAY = 800;

type DraftStatus = { kind: 'idle' } | { kind: 'saved'; at: string } | { kind: 'error' };

const SurveyForm = ({ token, response, onDone, onBlocked }: SurveyFormProps) => {
  const { survey, config, pendingAssets, draft } = response;
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [draftStatus, setDraftStatus] = useState<DraftStatus>({ kind: 'idle' });
  const draftTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const files = useMemo(() => participantFiles(token), [token]);
  // Brouillon repris, puis fichiers déjà déposés (ils comptent comme réponses)
  const initialAnswers = useMemo<Answers>(
    () => ({ ...(draft?.answers ?? {}), ...Object.fromEntries(Object.entries(pendingAssets).map(([questionId, assets]) => [questionId, assets.map((a) => a.id)])) }),
    [draft, pendingAssets]
  );

  useEffect(
    () => () => {
      if (draftTimer.current) clearTimeout(draftTimer.current);
    },
    []
  );

  /**
   * Enregistrement automatique du brouillon (specs_fonc §6.5), après une courte pause dans la saisie :
   * en revenant avec le même lien, le participant pourra reprendre. Un échec n'empêche jamais de répondre.
   */
  const saveProgress = (answers: Answers, questionId: string | null) => {
    if (draftTimer.current) clearTimeout(draftTimer.current);
    draftTimer.current = setTimeout(() => {
      saveDraft(token, toPayload(survey.questions, answers), questionId)
        .then((result) => setDraftStatus(result.savedAt ? { kind: 'saved', at: result.savedAt } : { kind: 'idle' }))
        .catch(() => setDraftStatus({ kind: 'error' }));
    }, DRAFT_DELAY);
  };

  /** Le brouillon n'est qu'un aide-mémoire : à l'envoi, toutes les réponses partent en une seule fois et sont validées. */
  const submit = async (answers: Answers): Promise<Record<string, string> | void> => {
    if (draftTimer.current) clearTimeout(draftTimer.current);
    setSubmitError(null);
    try {
      const result = await submitAnswers(token, toPayload(survey.questions, answers));
      onDone(result.message, result.submittedAt);
    } catch (error) {
      const blocked = dataOf<BlockedResponse>(error);
      if (blocked?.state) {
        onBlocked(blocked);
        return;
      }

      const violations = violationsOf(error);
      if (statusOf(error) === 400 && Object.keys(violations).length > 0) {
        setSubmitError('Certaines réponses sont à corriger.');
        return violations;
      }
      setSubmitError(errorMessage(error, 'L\'envoi a échoué. Vos réponses sont conservées : réessayez.'));
    }
  };

  return (
    <SurveyRunner
      survey={survey}
      platform={config}
      files={files}
      initialAnswers={initialAnswers}
      initialAssets={pendingAssets}
      validate
      finishLabel="Envoyer mes réponses"
      finishIcon="send"
      onFinish={submit}
      submitError={submitError}
      initialQuestionId={draft?.questionId ?? null}
      onProgress={saveProgress}
      footer={
        <>
          <DraftNote aria-live="polite" $error={draftStatus.kind === 'error'}>
            {draftStatus.kind === 'saved' && (
              <>
                <Icon name="check" size={14} /> Réponses enregistrées à {formatTime(draftStatus.at)} : vous pourrez reprendre plus tard avec le même lien.
              </>
            )}
            {draftStatus.kind === 'error' && 'Enregistrement automatique indisponible : pensez à envoyer vos réponses avant de quitter.'}
          </DraftNote>
          <Notice>Une seule participation est possible : vos réponses ne pourront plus être modifiées après l'envoi.</Notice>
        </>
      }
    />
  );
};

const formatTime = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });

/**
 * Retour sur un questionnaire commencé : reprendre là où l'on s'était arrêté, ou recommencer depuis le début
 * (brouillon et fichiers déjà déposés effacés).
 */
const ResumeScreen = ({ token, response, onResume, onRestart }: { token: string; response: OpenResponse; onResume: () => void; onRestart: () => void }) => {
  const [restarting, setRestarting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const savedAt = response.draft?.savedAt;
  const answered = Object.keys(response.draft?.answers ?? {}).length;

  const restart = async () => {
    setRestarting(true);
    setError(null);
    try {
      await discardDraft(token);
      onRestart();
    } catch (err) {
      setError(errorMessage(err, 'Impossible de recommencer pour le moment. Vous pouvez reprendre vos réponses.'));
      setRestarting(false);
    }
  };

  return (
    <StateBox role="region" aria-labelledby="resume-title">
      <StateIcon $tone="neutral">
        <Icon name="history" size={40} />
      </StateIcon>
      <h1 id="resume-title">{response.survey.title}</h1>
      <p>
        Vous avez commencé ce questionnaire{savedAt ? ` le ${formatDateTime(savedAt)}` : ''} ({answered} réponse{answered > 1 ? 's' : ''} déjà saisie{answered > 1 ? 's' : ''}). Vos réponses ont été
        conservées.
      </p>
      <ResumeActions>
        <Button $size="lg" onClick={onResume} autoFocus>
          <Icon name="chevron-right" size={18} /> Reprendre où j'en étais
        </Button>
        <Button $variant="outline" $size="lg" $loading={restarting} onClick={() => void restart()}>
          <Icon name="history" size={18} /> Recommencer
        </Button>
      </ResumeActions>
      <ResumeHint>Recommencer efface les réponses déjà saisies et les fichiers déposés.</ResumeHint>
      {error && <ResumeError role="alert">{error}</ResumeError>}
    </StateBox>
  );
};

const StateScreen = ({ icon, title, message, tone = 'neutral' }: { icon: string; title: string; message: string; tone?: 'neutral' | 'success' }) => (
  <StateBox role="status">
    <StateIcon $tone={tone}>
      <Icon name={icon} size={40} />
    </StateIcon>
    <h1>{title}</h1>
    <p>{message}</p>
  </StateBox>
);

const Page = styled.div`
  min-height: 100vh;
  background: ${({ theme }) => theme.colors.background.body};
`;

const NavBar = styled.header`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
  min-height: 52px;
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[4]}`};
  border-bottom: 1px solid ${({ theme }) => theme.colors.border.default};
  background: ${({ theme }) => theme.colors.background.surface};

  @media (max-width: 600px) {
    padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[3]}`};
  }
`;

const Brand = styled.span`
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1]};
  font-weight: 700;
  color: ${({ theme }) => theme.colors.primary.base};
`;

const NavTitle = styled.span`
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.9rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const Center = styled.div`
  display: grid;
  min-height: 60vh;
  place-items: center;
`;

const DraftNote = styled.p<{ $error: boolean }>`
  display: flex;
  align-items: center;
  justify-content: center;
  gap: ${({ theme }) => theme.spacing[1]};
  min-height: 1.2em;
  margin: ${({ theme }) => theme.spacing[4]} 0 0;
  text-align: center;
  font-size: 0.8rem;
  color: ${({ theme, $error }) => ($error ? theme.colors.warning.dark : theme.colors.success.dark)};
`;

const ResumeActions = styled.div`
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: ${({ theme }) => theme.spacing[3]};
  margin-top: ${({ theme }) => theme.spacing[5]};
`;

const ResumeHint = styled.p`
  margin-top: ${({ theme }) => theme.spacing[3]} !important;
  font-size: 0.8rem;
`;

const ResumeError = styled.p`
  margin-top: ${({ theme }) => theme.spacing[3]} !important;
  color: ${({ theme }) => theme.colors.danger.base} !important;
`;

const Notice = styled.p`
  margin: ${({ theme }) => theme.spacing[4]} 0 0;
  text-align: center;
  font-size: 0.8rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const StateBox = styled.section`
  margin: ${({ theme }) => theme.spacing[8]} auto;
  max-width: 520px;
  padding: 0 ${({ theme }) => theme.spacing[4]};
  text-align: center;

  h1 {
    margin: ${({ theme }) => theme.spacing[4]} 0 ${({ theme }) => theme.spacing[2]};
    font-size: 1.5rem;
  }

  p {
    margin: 0;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;

const StateIcon = styled.div<{ $tone: 'neutral' | 'success' }>`
  display: inline-grid;
  place-items: center;
  width: 80px;
  height: 80px;
  border-radius: 50%;
  background: ${({ theme, $tone }) => ($tone === 'success' ? theme.colors.success.subtle : theme.colors.background.surfaceHover)};
  color: ${({ theme, $tone }) => ($tone === 'success' ? theme.colors.success.base : theme.colors.text.muted)};
`;

const Footer = styled.footer`
  display: flex;
  justify-content: center;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1]};
  padding: ${({ theme }) => `${theme.spacing[7]} ${theme.spacing[4]} ${theme.spacing[5]}`};
  font-size: 0.8rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;
