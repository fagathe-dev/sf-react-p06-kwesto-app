import { useAsync } from '@/hooks/useAsync';
import { fetchSurvey, updateSurvey, validateSurvey } from '@/services/api';
import { dataOf, errorMessage, statusOf } from '@/services/errors';
import { useConfigStore } from '@/stores/config.store';
import { DISPLAY_MODE_ICONS, DISPLAY_MODE_LABELS, type DisplayMode, type QuestionType, type Survey, type ValidationIssue } from '@/types';
import { HelpBox } from '@/ui/HelpBox';
import { InlineEditableText } from '@/ui/InlineEditableText';
import { PageHeader, PageLoading } from '@/ui/Page';
import { Muted, Panel, Row, Stack } from '@/ui/primitives';
import { SurveyStatusBadge } from '@/ui/StatusBadge';
import {
  closestCorners,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useDroppable,
  useSensor,
  useSensors,
  type Announcements,
  type DragEndEvent,
  type DragOverEvent,
} from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Icon } from 'core-react/icons';
import { Alert, Button, ConfirmDialog, EmptyState, IconButton, SegmentedControl, TextareaField, TextField, ToggleField, useToast } from 'core-react/ui';
import { canBeConditionSource } from '@/pages/participant/conditionEvaluator';
import { estimateSurvey, formatEstimate, LONG_SURVEY_MINUTES } from '@/pages/participant/estimate';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { styled } from 'styled-components';
import {
  addSection,
  moveQuestion,
  newQuestion,
  numbering,
  orderedQuestions,
  questionsOf,
  removeSection,
  ROOT,
  toPublicSurvey,
  type BuilderQuestion,
  type BuilderSection,
  type BuilderState,
} from './builderModel';
import { AddQuestionButton, AddSectionDivider, InsertSlot } from './AddQuestion';
import { conditionIssue, dependentsOf, describeCondition, orderViolations, withoutConditionsOn, withoutOrderViolations } from './conditions';
import { QuestionCard } from './QuestionCard';
import { SurveyPreview } from './SurveyPreview';
import { useBuilder, type SaveStatus } from './useBuilder';


const SAVE_LABELS: Record<SaveStatus, string> = {
  idle: '',
  dirty: 'Modifications en attente…',
  saving: 'Enregistrement…',
  saved: 'Enregistré',
  error: 'Échec de l\'enregistrement',
};

/** Module 1 — conception du questionnaire (specs_fonc §3). */
export const SurveyBuilderPage = () => {
  const { id = '' } = useParams();
  const navigate = useNavigate();
  const { success, error: toastError } = useToast();
  const loadConfig = useConfigStore((state) => state.load);
  const platform = useConfigStore((state) => state.config);
  const [previewOpen, setPreviewOpen] = useState(false);
  const { data, error, isLoading, setData } = useAsync(() => fetchSurvey(id), [id]);
  const survey = data?.survey ?? null;
  const [issues, setIssues] = useState<ValidationIssue[]>([]);
  const [validating, setValidating] = useState(false);
  const [confirmValidate, setConfirmValidate] = useState(false);
  const [validateError, setValidateError] = useState<string | null>(null);

  useEffect(() => {
    void loadConfig().catch(() => undefined);
  }, [loadConfig]);

  const onSaved = (saved: Survey) => setData(data ? { ...data, survey: saved } : null);
  const { state, update, hold, flush, status, saveError, editable } = useBuilder(survey, onSaved);
  const numbers = useMemo(() => numbering(state), [state]);

  if (isLoading && !data) return <PageLoading />;
  if (error || !survey) return <Alert variant="danger">{error ?? 'Questionnaire introuvable.'}</Alert>;

  const validate = async () => {
    setConfirmValidate(false);
    setValidating(true);
    setValidateError(null);
    setIssues([]);
    try {
      await flush();
      await validateSurvey(survey.id);
      success('La structure est désormais verrouillée.', 'Questionnaire validé');
      navigate(`/surveys/${survey.id}/recipients`);
    } catch (err) {
      const body = dataOf<{ errors?: ValidationIssue[]; message?: string }>(err);
      if (statusOf(err) === 422 && body?.errors) {
        setIssues(body.errors);
        setValidateError(body.message ?? 'Le questionnaire ne peut pas encore être validé.');
      } else {
        setValidateError(errorMessage(err));
      }
    } finally {
      setValidating(false);
    }
  };

  // Titre et description : modifiables tant que la campagne n'est pas lancée (y compris une fois validé)
  const metadataEditable = survey.status === 'DRAFT' || survey.status === 'VALIDATED';
  const saveMetadata = async (data: { title?: string; description?: string | null; displayMode?: DisplayMode; showDuration?: boolean }) => {
    const { survey: saved } = await updateSurvey(survey.id, data);
    onSaved(saved);
  };

  const changeDisplayMode = (displayMode: DisplayMode) => {
    saveMetadata({ displayMode }).catch((err: unknown) => toastError(errorMessage(err), 'Affichage non modifié'));
  };

  const issuesFor = (targetId: string) => issues.filter((issue) => issue.id === targetId).map((issue) => issue.message);
  const globalIssues = issues.filter((issue) => issue.scope === 'survey');

  return (
    <>
      <PageHeader
        eyebrow={
          <Row $gap={2}>
            <SurveyStatusBadge status={survey.status} />
            {editable && <SaveIndicator $status={status}>{SAVE_LABELS[status]}</SaveIndicator>}
          </Row>
        }
        title={
          <InlineEditableText
            variant="heading"
            label="Titre du questionnaire"
            value={survey.title}
            editable={metadataEditable}
            maxLength={150}
            validate={(next) => (next.length < 3 || next.length > 150 ? 'Le titre doit contenir entre 3 et 150 caractères.' : null)}
            onSave={(title) => saveMetadata({ title })}
          />
        }
        lead={
          <InlineEditableText
            multiline
            label="Description et consignes"
            value={survey.description ?? ''}
            editable={metadataEditable}
            maxLength={10000}
            emptyText="Ajouter une description ou des consignes pour les participants…"
            placeholder="Affichée en tête du formulaire participant."
            onSave={(description) => saveMetadata({ description: description || null })}
          />
        }
        subtitle={`${numbers.size} question${numbers.size > 1 ? 's' : ''}${state.sections.length >= 2 ? ` · ${state.sections.length} sections (formulaire en étapes)` : ''}`}
        actions={
          <Row $gap={2}>
            <Button $variant="outline" onClick={() => setPreviewOpen(true)} disabled={numbers.size === 0} title={numbers.size === 0 ? 'Ajoutez au moins une question' : undefined}>
              <Icon name="eye-outline" size={18} /> Aperçu
            </Button>
            {editable ? (
              <Button onClick={() => setConfirmValidate(true)} $loading={validating}>
                <Icon name="lock-outline" size={18} /> Valider le questionnaire
              </Button>
            ) : survey.status === 'VALIDATED' ? (
              <Button href={`/surveys/${survey.id}/recipients`}>Configurer la diffusion</Button>
            ) : (
              <Button href={`/surveys/${survey.id}/stats`}>Voir les statistiques</Button>
            )}
          </Row>
        }
      />

      <Stack $gap={4}>
        {!editable && (
          <HelpBox title="Structure verrouillée">
            Ce questionnaire a été validé : ses sections et ses questions ne sont plus modifiables, pour garantir l'intégrité des statistiques. Dupliquez-le
            depuis la liste pour repartir d'une copie modifiable.
          </HelpBox>
        )}
        {saveError && <Alert variant="danger" title="Enregistrement impossible">{saveError}</Alert>}
        {validateError && (
          <Alert variant="danger" title="Validation impossible">
            {validateError}
            {globalIssues.length > 0 && (
              <ul>
                {globalIssues.map((issue) => (
                  <li key={issue.message}>{issue.message}</li>
                ))}
              </ul>
            )}
            {issues.length > globalIssues.length && <p>Les éléments à corriger sont signalés en rouge ci-dessous.</p>}
          </Alert>
        )}

        <SurveyOptions
          state={state}
          displayMode={survey.displayMode}
          showDuration={survey.showDuration}
          editable={metadataEditable}
          onDisplayMode={changeDisplayMode}
          onShowDuration={(showDuration) => void saveMetadata({ showDuration }).catch((err: unknown) => toastError(errorMessage(err), 'Option non modifiée'))}
        />

        <Structure state={state} update={update} hold={hold} numbers={numbers} locked={!editable} issuesFor={issuesFor} />
      </Stack>

      <ConfirmDialog
        isOpen={confirmValidate}
        onClose={() => setConfirmValidate(false)}
        onConfirm={() => void validate()}
        title="Valider le questionnaire ?"
        message="Une fois validé, il ne sera plus possible d'ajouter, supprimer, réordonner ou modifier les questions et les sections. Vous pourrez ensuite configurer la liste de diffusion."
        confirmLabel="Valider et verrouiller"
        variant="primary"
      />

      {previewOpen && (
        <SurveyPreview
          survey={toPublicSurvey({ id: survey.id, title: survey.title, description: survey.description, expiresAt: survey.expiresAt, displayMode: survey.displayMode, showDuration: survey.showDuration }, state)}
          platform={platform}
          onClose={() => setPreviewOpen(false)}
        />
      )}
    </>
  );
};

const DISPLAY_MODES: DisplayMode[] = ['LIST', 'ONE_BY_ONE'];

/** Explication du rendu participant, selon le mode et la présence d'un stepper (≥ 2 sections). */
const displayModeHint = (mode: DisplayMode, sections: number): string => {
  const stepper = sections >= 2;
  if (mode === 'LIST') {
    return stepper ? 'Toutes les questions d\'une section sur le même écran, une section après l\'autre (stepper).' : 'Toutes les questions sur un seul écran, avec une barre de progression.';
  }
  return stepper ? 'Une question par écran avec « Suivant » ; le stepper indique la section en cours.' : 'Une question par écran avec « Suivant » et une barre de progression.';
};

interface SurveyOptionsProps {
  state: BuilderState;
  displayMode: DisplayMode;
  showDuration: boolean;
  editable: boolean;
  onDisplayMode: (mode: DisplayMode) => void;
  onShowDuration: (show: boolean) => void;
}

/**
 * Options du questionnaire côté participant (présentation, modifiables jusqu'au lancement, même après validation) :
 * mode d'affichage des questions et durée estimée. Le nombre de questions est toujours affiché au participant ;
 * la durée est facultative, à masquer pour un questionnaire long afin de ne pas décourager au démarrage.
 */
const SurveyOptions = ({ state, displayMode, showDuration, editable, onDisplayMode, onShowDuration }: SurveyOptionsProps) => {
  const questions = orderedQuestions(state);
  const estimate = estimateSurvey(questions);
  const long = estimate.maxMinutes > LONG_SURVEY_MINUTES;

  return (
    <Options aria-labelledby="survey-options-title">
      <OptionsTitle id="survey-options-title">Options du questionnaire</OptionsTitle>

      <OptionRow>
        <SettingLabel>Affichage des questions</SettingLabel>
        {editable ? (
          <SegmentedControl<DisplayMode>
            aria-label="Affichage des questions"
            size="sm"
            value={displayMode}
            onChange={onDisplayMode}
            options={DISPLAY_MODES.map((mode) => ({ value: mode, label: DISPLAY_MODE_LABELS[mode], icon: DISPLAY_MODE_ICONS[mode] }))}
          />
        ) : (
          <strong>
            <Icon name={DISPLAY_MODE_ICONS[displayMode]} size={16} /> {DISPLAY_MODE_LABELS[displayMode]}
          </strong>
        )}
        <Muted>{displayModeHint(displayMode, state.sections.length)}</Muted>
      </OptionRow>

      <OptionRow>
        <ToggleField label="Afficher la durée estimée" checked={showDuration} disabled={!editable} onChange={(e) => onShowDuration(e.target.checked)} />
        <Muted>
          {questions.length > 0
            ? `Le participant verra : « ${formatEstimate(estimate, showDuration)} ». Le nombre de questions est toujours affiché.`
            : 'Ajoutez des questions pour voir l\'estimation.'}
        </Muted>
        {long && showDuration && <Muted>Questionnaire long : masquer la durée peut limiter les abandons dès l'ouverture.</Muted>}
      </OptionRow>
    </Options>
  );
};

interface StructureProps {
  state: BuilderState;
  update: (updater: (current: BuilderState) => BuilderState) => void;
  /** Suspend l'enregistrement automatique pendant un glisser-déposer. */
  hold: (value: boolean) => void;
  numbers: Map<string, number>;
  locked: boolean;
  issuesFor: (id: string) => string[];
}

const ANNOUNCEMENTS: Announcements = {
  onDragStart: () => 'Question saisie. Utilisez les flèches pour la déplacer, Espace pour la déposer.',
  onDragOver: ({ over }) => (over ? 'Position modifiée.' : 'Hors de la liste.'),
  onDragEnd: ({ over }) => (over ? 'Question déposée. La numérotation a été mise à jour.' : 'Déplacement annulé.'),
  onDragCancel: () => 'Déplacement annulé.',
};

/**
 * Sections et questions. Glisser-déposer des questions au sein d'une section et d'une section à
 * l'autre ; la numérotation est recalculée immédiatement puis enregistrée.
 */
const Structure = ({ state, update, hold, numbers, locked, issuesFor }: StructureProps) => {
  const [toRemove, setToRemove] = useState<BuilderQuestion | null>(null);
  // Déplacement qui placerait une question conditionnelle au-dessus de sa source : confirmation
  const [pendingMove, setPendingMove] = useState<{ before: BuilderState; after: BuilderState; broken: number } | null>(null);
  const dragSnapshot = useRef<BuilderState | null>(null);
  // Accordéon : une seule question dépliée à la fois
  const [expandedId, setExpandedId] = useState<string | null>(null);
  // Question tout juste ajoutée : son intitulé reçoit le focus
  const [focusId, setFocusId] = useState<string | null>(null);
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));
  const containers = state.sections.length === 0 ? [ROOT] : state.sections.map((s) => s.id);

  const containerOf = (questionId: string): string => {
    if (state.sections.length === 0) return ROOT;
    const question = state.questions.find((q) => q.id === questionId);
    return question?.sectionId ?? state.sections[0]?.id ?? ROOT;
  };

  const resolveOver = (event: DragOverEvent | DragEndEvent): { container: string; questionId: string | null } | null => {
    const { over } = event;
    if (!over) return null;
    const overId = String(over.id);
    if (containers.includes(overId)) return { container: overId, questionId: null };
    return { container: containerOf(overId), questionId: overId };
  };

  const onDragOver = (event: DragOverEvent) => {
    const target = resolveOver(event);
    const activeId = String(event.active.id);
    if (!target || target.container === containerOf(activeId)) return;

    update((current) => moveQuestion(current, activeId, target.container, target.questionId));
  };

  const onDragStart = () => {
    dragSnapshot.current = state;
    hold(true);
  };

  const onDragEnd = (event: DragEndEvent) => {
    const target = resolveOver(event);
    const activeId = String(event.active.id);
    let next = state;

    if (target?.questionId && target.questionId !== activeId) {
      const items = questionsOf(state, target.container === ROOT ? ROOT : target.container).map((q) => q.id);
      const from = items.indexOf(activeId);
      const to = items.indexOf(target.questionId);
      if (from !== -1 && to !== -1) {
        const reordered = arrayMove(items, from, to);
        next = moveQuestion(state, activeId, target.container, reordered[to + 1] ?? null);
      }
    }

    if (next !== state) update(() => next);

    // Une question ne peut dépendre que d'une question placée avant elle (specs_fonc §3.5)
    const broken = orderViolations(next).length;
    if (broken > 0 && dragSnapshot.current) {
      setPendingMove({ before: dragSnapshot.current, after: next, broken });
      return;
    }
    hold(false);
  };

  const onDragCancel = () => {
    if (dragSnapshot.current) update(() => dragSnapshot.current!);
    hold(false);
  };

  /** Changer le type d'une question retire les conditions des questions qui en dépendaient. */
  const changeQuestion = (question: BuilderQuestion) =>
    update((current) => {
      const previous = current.questions.find((q) => q.id === question.id);
      const next = { ...current, questions: current.questions.map((q) => (q.id === question.id ? question : q)) };
      // Type changé, ou question passée en choix multiple : elle ne peut plus servir de condition
      const noLongerSource = previous && (previous.type !== question.type || (canBeConditionSource(previous) && !canBeConditionSource(question)));
      return noLongerSource ? withoutConditionsOn(next, question.id) : next;
    });

  // Affichage conditionnel : sources possibles et résumé de chaque question
  const ordered = orderedQuestions(state);
  const allQuestions = ordered.map((question) => ({ question, number: numbers.get(question.id) ?? 0 }));
  const conditionProps = (question: BuilderQuestion) => {
    const index = ordered.findIndex((q) => q.id === question.id);
    const condition = question.displayCondition;
    const source = condition ? allQuestions.find((s) => s.question.id === condition.sourceQuestionId) : undefined;
    return {
      conditionSources: allQuestions.slice(0, Math.max(0, index)).filter((s) => canBeConditionSource(s.question)),
      allQuestions,
      conditionIssue: conditionIssue(state, question),
      conditionSummary: condition ? describeCondition(condition, source?.question, source?.number) : null,
    };
  };

  /** La copie s'insère juste après l'original et s'ouvre à sa place. */
  const duplicate = (question: BuilderQuestion) => {
    const copy = { ...newQuestion(question.type, question.sectionId), label: `${question.label} (copie)`, required: question.required, helpTitle: question.helpTitle, helpText: question.helpText, config: structuredClone(question.config) };
    setExpandedId(copy.id);
    update((current) => {
      const index = current.questions.findIndex((q) => q.id === question.id);
      const questions = [...current.questions];
      questions.splice(index + 1, 0, copy);
      return { ...current, questions };
    });
  };

  /** Ajoute une question dans un conteneur, avant `beforeId` (ou à la fin) ; elle s'ouvre, les autres se replient. */
  const insertQuestion = (type: QuestionType, containerId: string, beforeId: string | null = null) => {
    const question = newQuestion(type, containerId === ROOT ? null : containerId);
    setFocusId(question.id);
    setExpandedId(question.id);
    update((current) => moveQuestion({ ...current, questions: [...current.questions, question] }, question.id, containerId, beforeId));
  };

  const renderQuestions = (containerId: string) => {
    const items = questionsOf(state, containerId);

    return (
      <SortableContext items={items.map((q) => q.id)} strategy={verticalListSortingStrategy}>
        <Dropzone
          id={containerId}
          empty={items.length === 0}
          locked={locked}
          emptyContent={<AddQuestionButton empty onAdd={(type) => insertQuestion(type, containerId)} />}
        >
          {items.map((question, index) => (
            <Fragment key={question.id}>
              <QuestionCard
                question={question}
                number={numbers.get(question.id) ?? 0}
                locked={locked}
                expanded={question.id === expandedId}
                onToggle={() => setExpandedId((current) => (current === question.id ? null : question.id))}
                autoFocus={question.id === focusId}
                issues={issuesFor(question.id)}
                {...conditionProps(question)}
                onChange={changeQuestion}
                onDuplicate={() => duplicate(question)}
                onRemove={() => setToRemove(question)}
              />
              {!locked && index < items.length - 1 && (
                <InsertSlot after={numbers.get(question.id) ?? 0} onAdd={(type) => insertQuestion(type, containerId, items[index + 1]?.id ?? null)} />
              )}
            </Fragment>
          ))}
        </Dropzone>
        {!locked && items.length > 0 && <AddQuestionButton onAdd={(type) => insertQuestion(type, containerId)} />}
      </SortableContext>
    );
  };

  return (
    <>
      {state.sections.length >= 2 && <SectionOutline state={state} update={update} locked={locked} />}

      <DndContext
        sensors={sensors}
        collisionDetection={closestCorners}
        onDragStart={onDragStart}
        onDragOver={onDragOver}
        onDragEnd={onDragEnd}
        onDragCancel={onDragCancel}
        accessibility={{ announcements: ANNOUNCEMENTS }}
      >
        {state.sections.length === 0 ? (
          state.questions.length === 0 && locked ? (
            <EmptyState icon="format-list-checks" title="Aucune question" />
          ) : (
            <Stack $gap={3}>{renderQuestions(ROOT)}</Stack>
          )
        ) : (
          <Stack $gap={5}>
            {state.sections.map((section, index) => (
              <SectionBlock
                key={section.id}
                section={section}
                index={index}
                count={state.sections.length}
                locked={locked}
                issues={issuesFor(section.id)}
                onChange={(patch) => update((current) => ({ ...current, sections: current.sections.map((s) => (s.id === section.id ? { ...s, ...patch } : s)) }))}
                onRemove={() => update((current) => removeSection(current, section.id))}
              >
                {renderQuestions(section.id)}
              </SectionBlock>
            ))}
          </Stack>
        )}
      </DndContext>

      {!locked && <AddSectionDivider sections={state.sections.length} onAdd={() => update(addSection)} />}

      <ConfirmDialog
        isOpen={toRemove !== null}
        onClose={() => setToRemove(null)}
        onConfirm={() => {
          const target = toRemove;
          setToRemove(null);
          // Les conditions qui dépendaient de cette question sont retirées : ces questions redeviennent toujours affichées
          if (target) update((current) => withoutConditionsOn({ ...current, questions: current.questions.filter((q) => q.id !== target.id) }, target.id));
        }}
        title="Supprimer la question ?"
        message={`« ${toRemove?.label || 'Question sans intitulé'} » sera supprimée. Les questions suivantes seront renumérotées.${
          toRemove && dependentsOf(state, toRemove.id).length > 0
            ? ` ${dependentsOf(state, toRemove.id).length} question(s) en dépendent : leur condition d'affichage sera retirée et elles seront toujours affichées.`
            : ''
        }`}
        confirmLabel="Supprimer"
      />

      <ConfirmDialog
        isOpen={pendingMove !== null}
        onClose={() => {
          // Annuler : retour à l'ordre d'avant le déplacement
          const move = pendingMove;
          setPendingMove(null);
          if (move) update(() => move.before);
          hold(false);
        }}
        onConfirm={() => {
          const move = pendingMove;
          setPendingMove(null);
          if (move) update(() => withoutOrderViolations(move.after));
          hold(false);
        }}
        title="Déplacer et retirer la condition ?"
        message={`Ce déplacement placerait ${pendingMove?.broken === 1 ? 'une question conditionnelle' : `${pendingMove?.broken ?? 0} questions conditionnelles`} avant la question dont elle dépend. Une question ne peut dépendre que d'une question placée avant elle : la condition sera retirée et la question toujours affichée.`}
        confirmLabel="Retirer la condition et déplacer"
        variant="primary"
      />
    </>
  );
};

interface DropzoneProps {
  id: string;
  empty: boolean;
  locked: boolean;
  /** Contenu d'une section vide : le choix du type de la première question (on peut aussi y déposer une question). */
  emptyContent: React.ReactNode;
  children: React.ReactNode;
}

const Dropzone = ({ id, empty, locked, emptyContent, children }: DropzoneProps) => {
  const { setNodeRef, isOver } = useDroppable({ id, data: { kind: 'container' }, disabled: locked });

  return (
    <DropArea ref={setNodeRef} $over={isOver}>
      {empty && !locked ? emptyContent : children}
    </DropArea>
  );
};

interface SectionBlockProps {
  section: BuilderSection;
  index: number;
  count: number;
  locked: boolean;
  issues: string[];
  onChange: (patch: Partial<BuilderSection>) => void;
  onRemove: () => void;
  children: React.ReactNode;
}

const SectionBlock = ({ section, index, count, locked, issues, onChange, onRemove, children }: SectionBlockProps) => (
  <SectionCard aria-label={`Section ${index + 1}`} $invalid={issues.length > 0}>
    <SectionHead>
      <SectionNumber>
        Section {index + 1} / {count}
      </SectionNumber>
      {!locked && <IconButton name="trash-can-outline" size="sm" variant="ghost" aria-label={`Supprimer la section ${index + 1}`} onClick={onRemove} />}
    </SectionHead>
    {issues.length > 0 && (
      <Alert variant="danger">
        {issues.map((issue) => (
          <div key={issue}>{issue}</div>
        ))}
      </Alert>
    )}
    <TextField label="Titre de la section" required maxLength={150} value={section.title} disabled={locked} onChange={(e) => onChange({ title: e.target.value })} />
    <TextareaField label="Description (affichée en tête d'étape)" rows={2} value={section.description} disabled={locked} onChange={(e) => onChange({ description: e.target.value })} />
    <Stack $gap={3}>{children}</Stack>
    {!locked && count > 1 && <Muted>Supprimer la section rattache ses questions à la section précédente.</Muted>}
  </SectionCard>
);

/** Plan des sections : réordonnancement par glisser-déposer (ou clavier). */
const SectionOutline = ({ state, update, locked }: { state: BuilderState; update: StructureProps['update']; locked: boolean }) => {
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }), useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }));

  const onDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over || active.id === over.id) return;
    update((current) => {
      const ids = current.sections.map((s) => s.id);
      const reordered = arrayMove(current.sections, ids.indexOf(String(active.id)), ids.indexOf(String(over.id)));
      return { ...current, sections: reordered };
    });
  };

  return (
    <Panel aria-label="Plan du questionnaire">
      <OutlineTitle>Étapes du formulaire</OutlineTitle>
      <DndContext sensors={sensors} collisionDetection={closestCorners} onDragEnd={onDragEnd}>
        <SortableContext items={state.sections.map((s) => s.id)} strategy={verticalListSortingStrategy}>
          <Outline>
            {state.sections.map((section, index) => (
              <OutlineItem key={section.id} section={section} index={index} questions={questionsOf(state, section.id).length} locked={locked} />
            ))}
          </Outline>
        </SortableContext>
      </DndContext>
    </Panel>
  );
};

const OutlineItem = ({ section, index, questions, locked }: { section: BuilderSection; index: number; questions: number; locked: boolean }) => {
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition } = useSortable({ id: section.id, disabled: locked });

  return (
    <li ref={setNodeRef} style={{ transform: CSS.Transform.toString(transform), transition }}>
      {!locked && (
        <OutlineHandle ref={setActivatorNodeRef} type="button" aria-label={`Déplacer la section ${index + 1}`} {...attributes} {...listeners}>
          <Icon name="drag-vertical" size={18} />
        </OutlineHandle>
      )}
      <strong>{index + 1}.</strong> <span>{section.title || 'Section sans titre'}</span>
      <Muted>
        {questions} question{questions > 1 ? 's' : ''}
      </Muted>
    </li>
  );
};

const Options = styled.section`
  padding: ${({ theme }) => `${theme.spacing[3]} ${theme.spacing[4]}`};
  border: 1px solid ${({ theme }) => theme.colors.border.default};
  border-radius: ${({ theme }) => theme.radii.lg};
  background: ${({ theme }) => theme.colors.background.surface};
`;

const OptionsTitle = styled.h2`
  margin: 0 0 ${({ theme }) => theme.spacing[2]};
  font-size: 0.8rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const OptionRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[3]}`};
  padding: ${({ theme }) => theme.spacing[2]} 0;

  & + & {
    border-top: 1px solid ${({ theme }) => theme.colors.border.default};
  }

  strong {
    display: inline-flex;
    align-items: center;
    gap: ${({ theme }) => theme.spacing[1]};
    font-size: 0.875rem;
  }
`;

const SettingLabel = styled.span`
  font-size: 0.875rem;
  font-weight: 600;
  color: ${({ theme }) => theme.colors.text.heading};
`;

const SaveIndicator = styled.span<{ $status: SaveStatus }>`
  font-size: 0.8rem;
  color: ${({ theme, $status }) => ($status === 'error' ? theme.colors.danger.base : $status === 'saved' ? theme.colors.success.base : theme.colors.text.muted)};
`;

const DropArea = styled.div<{ $over: boolean }>`
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[3]};
  border-radius: ${({ theme }) => theme.radii.lg};
  outline: 2px dashed ${({ theme, $over }) => ($over ? theme.colors.primary.base : 'transparent')};
  outline-offset: 4px;
`

const SectionCard = styled.section<{ $invalid: boolean }>`
  padding: ${({ theme }) => theme.spacing[5]};
  border-radius: ${({ theme }) => theme.radii.xl};
  border: 1px solid ${({ theme, $invalid }) => ($invalid ? theme.colors.danger.base : theme.colors.border.default)};
  border-left: 6px solid ${({ theme }) => theme.colors.primary.base};
  background: color-mix(in srgb, ${({ theme }) => theme.colors.primary.subtle} 35%, ${({ theme }) => theme.colors.background.body});
`;

const SectionHead = styled.div`
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: ${({ theme }) => theme.spacing[2]};
`;

const SectionNumber = styled.span`
  font-size: 0.8rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: ${({ theme }) => theme.colors.primary.base};
`;

const OutlineTitle = styled.h2`
  margin: 0 0 ${({ theme }) => theme.spacing[2]};
  font-size: 1rem;
`;

const Outline = styled.ol`
  list-style: none;
  margin: 0;
  padding: 0;

  li {
    display: flex;
    align-items: center;
    gap: ${({ theme }) => theme.spacing[2]};
    padding: ${({ theme }) => theme.spacing[1]} 0;
    background: ${({ theme }) => theme.colors.background.surface};

    span {
      flex: 1;
    }
  }
`;

const OutlineHandle = styled.button`
  display: inline-flex;
  padding: 2px;
  border: 0;
  background: transparent;
  color: ${({ theme }) => theme.colors.text.muted};
  cursor: grab;
  touch-action: none;
`;
