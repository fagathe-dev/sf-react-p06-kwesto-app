import type { Answers, AnswerValue, Asset, DisplayMode, PlatformConfig, PublicSurvey } from '@/types';
import { plainText } from '@/ui/markdownText';
import { Icon } from 'core-react/icons';
import { Alert, Button } from 'core-react/ui';
import { useEffect, useMemo, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { styled } from 'styled-components';
import { AnswerField, type FileTransport } from './AnswerField';
import { ProgressBar, StepHeading, StepTrail, SurveyIntro } from './FormChrome';
import { FormNav, Questions, StickyInner, StickyProgress } from './formLayout';
import { pruneHidden, visibleQuestionIds } from './conditionEvaluator';
import { estimateSurvey, formatEstimate } from './estimate';
import { buildFlow, defaultAnswers, progressLabel, progressPercent, validateQuestions } from './participantModel';

interface SurveyRunnerProps {
  survey: PublicSurvey;
  /** Par défaut, le mode enregistré sur le questionnaire. */
  mode?: DisplayMode;
  platform: PlatformConfig;
  files: FileTransport;
  initialAnswers?: Answers;
  initialAssets?: Record<string, Asset[]>;
  /** Contrôle des réponses avant « Suivant » et avant la fin (toujours actif pour le participant). */
  validate: boolean;
  /** Aperçu : les sections du stepper deviennent cliquables. */
  navigableStepper?: boolean;
  finishLabel: string;
  finishIcon: string;
  /**
   * Fin du parcours. Peut renvoyer des erreurs par question (rejet serveur) : le parcours revient alors
   * sur le premier écran concerné.
   */
  onFinish: (answers: Answers) => Promise<Record<string, string> | void> | void;
  submitError?: string | null;
  footer?: ReactNode;
  /** Reprise d'un brouillon : le parcours démarre sur l'écran qui contient cette question. */
  initialQuestionId?: string | null;
  /**
   * Appelé à chaque changement de réponse ou d'écran (pas au montage) : réponses visibles et première question
   * de l'écran courant. Sert à l'enregistrement automatique du brouillon (specs_fonc §6.5).
   */
  onProgress?: (answers: Answers, questionId: string | null) => void;
}

/**
 * Parcours du formulaire participant, commun au lien /s/:token et à l'aperçu du builder.
 * Bandeau collé en haut (stepper si ≥ 2 sections, puis progression), écran courant, navigation.
 */
export const SurveyRunner = ({
  survey,
  mode,
  platform,
  files,
  initialAnswers = {},
  initialAssets = {},
  validate,
  navigableStepper = false,
  finishLabel,
  finishIcon,
  onFinish,
  submitError = null,
  footer,
  initialQuestionId = null,
  onProgress,
}: SurveyRunnerProps) => {
  const ordered = useMemo(() => [...survey.questions].sort((a, b) => a.position - b.position), [survey]);
  // Numérotation du builder, conservée même quand des questions conditionnelles sont masquées
  const numbers = useMemo(() => new Map(ordered.map((q, i) => [q.id, i + 1])), [ordered]);
  // Nombre de questions toujours affiché ; durée estimée selon l'option du questionnaire
  const estimate = useMemo(() => (ordered.length > 0 ? formatEstimate(estimateSurvey(ordered), survey.showDuration) : null), [survey.showDuration, ordered]);

  // Valeurs par défaut (BOOLEAN, RANGE), complétées par ce qui a déjà été saisi (brouillon, fichiers déposés)
  const [answers, setAnswers] = useState<Answers>(() => pruneHidden(ordered, { ...defaultAnswers(survey.questions), ...initialAnswers }));
  // Reprise : écran qui contient la question où le participant s'était arrêté (sinon le premier)
  const [current, setCurrent] = useState(() => {
    if (!initialQuestionId) return 0;
    const startFlow = buildFlow(survey, mode, visibleQuestionIds(ordered, answers));
    return Math.max(0, startFlow.pages.findIndex((p) => p.questions.some((q) => q.id === initialQuestionId)));
  });
  const [assets, setAssets] = useState<Record<string, Asset[]>>(initialAssets);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitting, setSubmitting] = useState(false);
  const [announcement, setAnnouncement] = useState('');
  const rootRef = useRef<HTMLFormElement>(null);
  const contentRef = useRef<HTMLDivElement>(null);

  // Conditions réévaluées à chaque réponse : les questions masquées ne sont ni affichées, ni validées, ni envoyées
  const visible = useMemo(() => visibleQuestionIds(ordered, answers), [ordered, answers]);
  const flow = useMemo(() => buildFlow(survey, mode, visible), [survey, mode, visible]);

  const index = Math.min(current, flow.pages.length - 1);
  const page = flow.pages[index]!;
  const isLast = index === flow.pages.length - 1;
  const section = page.section !== null ? flow.stepper[page.section] : undefined;
  const errorCount = page.questions.filter((q) => errors[q.id]).length;
  const pageQuestionId = page.questions[0]?.id ?? null;

  // Enregistrement automatique : signalé à chaque changement de réponse ou d'écran, jamais au montage
  const progressRef = useRef(onProgress);
  const mounted = useRef(false);
  useEffect(() => {
    progressRef.current = onProgress;
  });
  useEffect(() => {
    if (!mounted.current) {
      mounted.current = true;
      return;
    }
    progressRef.current?.(answers, pageQuestionId);
  }, [answers, pageQuestionId]);

  const setAnswer = (questionId: string, value: AnswerValue) => {
    const next = pruneHidden(ordered, { ...answers, [questionId]: value });
    const nextVisible = visibleQuestionIds(ordered, next);

    // Une question qui redevient masquée perd sa valeur ; ses fichiers déposés sont retirés
    const hiddenWithFiles = Object.keys(assets).filter((id) => !nextVisible.has(id) && (assets[id]?.length ?? 0) > 0);
    if (hiddenWithFiles.length > 0) {
      hiddenWithFiles.forEach((id) => assets[id]?.forEach((asset) => void files.remove(asset.id).catch(() => undefined)));
      setAssets((prev) => Object.fromEntries(Object.entries(prev).filter(([id]) => nextVisible.has(id))));
    }

    // Apparition annoncée aux technologies d'assistance
    const appeared = ordered.filter((q) => nextVisible.has(q.id) && !visible.has(q.id));
    if (appeared.length > 0) {
      setAnnouncement(appeared.map((q) => `Nouvelle question : ${plainText(q.label)}`).join('. '));
    }

    setAnswers(next);
    if (errors[questionId]) setErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([key]) => key !== questionId)));
  };

  const focusQuestion = (questionId: string | undefined) =>
    requestAnimationFrame(() => {
      const field = questionId ? document.getElementById(`q-${questionId}`) : null;
      (field ?? contentRef.current)?.focus({ preventScroll: true });
    });

  /** Change d'écran : retour en haut, focus sur la question (une par écran) ou sur le contenu. */
  const goTo = (target: number) => {
    setCurrent(target);
    rootRef.current?.scrollIntoView?.({ block: 'start' });
    focusQuestion(flow.mode === 'ONE_BY_ONE' ? flow.pages[target]?.questions[0]?.id : undefined);
  };

  const showErrors = (found: Record<string, string>) => {
    setErrors(found);
    const target = flow.pages.findIndex((p) => p.questions.some((q) => found[q.id]));
    if (target === -1) return;
    if (target !== index) goTo(target);
    focusQuestion(flow.pages[target]?.questions.find((q) => found[q.id])?.id);
  };

  const next = () => {
    if (validate) {
      const found = validateQuestions(page.questions, answers);
      if (Object.keys(found).length > 0) {
        showErrors(found);
        return;
      }
    }
    setErrors({});
    goTo(index + 1);
  };

  const finish = async () => {
    if (validate) {
      const found = validateQuestions(
        flow.pages.flatMap((p) => p.questions),
        answers
      );
      if (Object.keys(found).length > 0) {
        showErrors(found);
        return;
      }
    }

    setSubmitting(true);
    try {
      const rejected = await onFinish(answers);
      if (rejected && Object.keys(rejected).length > 0) showErrors(rejected);
    } finally {
      setSubmitting(false);
    }
  };

  /** Entrée dans un champ : passe à l'écran suivant, mais n'envoie jamais le questionnaire. */
  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    if (!isLast) next();
  };

  return (
    <form ref={rootRef} onSubmit={onSubmit} noValidate>
      <StickyProgress>
        <StickyInner>
          {flow.stepper.length > 0 && page.section !== null && (
            <StepTrail
              steps={flow.stepper}
              current={page.section}
              onSelect={navigableStepper ? (target) => goTo(Math.max(0, flow.pages.findIndex((p) => p.section === target))) : undefined}
            />
          )}
          <ProgressBar label={progressLabel(flow, index, answers)} percent={progressPercent(flow, index, answers)} />
        </StickyInner>
      </StickyProgress>

      <Content ref={contentRef} tabIndex={-1}>
        {index === 0 && <SurveyIntro title={survey.title} description={survey.description} expiresAt={survey.expiresAt} estimate={estimate} />}
        {section && page.firstOfSection && <StepHeading title={section.title} description={section.description} />}

        {errorCount > 0 && (
          <Spaced>
            <Alert variant="danger">{errorCount === 1 ? 'Une réponse est à corriger.' : `${errorCount} réponses sont à corriger.`}</Alert>
          </Spaced>
        )}

        <Questions>
          {page.questions.length === 0 && <Alert variant="warning">Cette étape ne contient aucune question.</Alert>}
          {page.questions.map((question) => (
            <AnswerField
              key={question.id}
              question={question}
              number={numbers.get(question.id) ?? 0}
              value={answers[question.id]}
              error={errors[question.id]}
              files={files}
              platform={platform}
              assets={assets[question.id] ?? []}
              onChange={(value) => setAnswer(question.id, value)}
              onAssetsChange={(list) => setAssets((prev) => ({ ...prev, [question.id]: list }))}
            />
          ))}
        </Questions>

        {submitError && (
          <Spaced>
            <Alert variant="danger">{submitError}</Alert>
          </Spaced>
        )}

        <FormNav>
          {index > 0 ? (
            <Button type="button" $variant="ghost" onClick={() => goTo(index - 1)}>
              <Icon name="chevron-left" size={18} /> Précédent
            </Button>
          ) : (
            <span />
          )}
          {isLast ? (
            <Button type="button" $size="lg" $loading={submitting} onClick={() => void finish()}>
              <Icon name={finishIcon} size={18} /> {finishLabel}
            </Button>
          ) : (
            <Button type="submit" $size="lg">
              Suivant <Icon name="chevron-right" size={18} />
            </Button>
          )}
        </FormNav>
        {footer}
        <LiveRegion aria-live="polite" role="status">
          {announcement}
        </LiveRegion>
      </Content>
    </form>
  );
};

const Content = styled.div`
  max-width: 760px;
  margin: 0 auto;
  padding: ${({ theme }) => `${theme.spacing[5]} ${theme.spacing[4]} 0`};

  &:focus {
    outline: none;
  }

  @media (max-width: 600px) {
    padding: ${({ theme }) => `${theme.spacing[4]} ${theme.spacing[3]} 0`};
  }
`;

const LiveRegion = styled.div`
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  white-space: nowrap;
`;

const Spaced = styled.div`
  margin: ${({ theme }) => theme.spacing[4]} 0;
`;
