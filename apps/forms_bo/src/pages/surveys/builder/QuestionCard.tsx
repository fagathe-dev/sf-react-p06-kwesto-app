import { effectiveHelpText } from '@/pages/participant/helpText';
import { QUESTION_TYPE_ICONS, QUESTION_TYPE_LABELS } from '@/types';
import { HelpBox } from '@/ui/HelpBox';
import { InlineMarkdown, MarkdownBlock } from '@/ui/Markdown';
import { plainText } from '@/ui/markdownText';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Icon } from 'core-react/icons';
import { IconButton, MarkdownEditor, TextField, ToggleField } from 'core-react/ui';
import { useId, type ComponentProps } from 'react';
import { styled } from 'styled-components';
import type { BuilderQuestion } from './builderModel';
import { defaultConfig } from './builderModel';
import { ConfigEditor } from './ConfigEditors';
import { DisplayConditionEditor, type ConditionSource } from './DisplayConditionEditor';
import { QuestionTypeSelect } from './QuestionTypeSelect';

type MarkdownActions = NonNullable<ComponentProps<typeof MarkdownEditor>['actions']>;

/** Intitulé : Markdown en ligne seulement (rendu dans un `<label>`). */
const LABEL_ACTIONS: MarkdownActions = ['bold', 'italic', 'strike', 'highlight', 'separator', 'code', 'link'];
/** Aide : Markdown en bloc (listes, citations). */
const HELP_ACTIONS: MarkdownActions = ['bold', 'italic', 'highlight', 'separator', 'link', 'ul', 'ol', 'quote'];

interface QuestionCardProps {
  question: BuilderQuestion;
  number: number;
  locked: boolean;
  /** Une seule question dépliée à la fois : les autres se résument à une ligne. */
  expanded: boolean;
  onToggle: () => void;
  /** Question tout juste ajoutée : focus sur son intitulé. */
  autoFocus?: boolean;
  issues: string[];
  /** Affichage conditionnel : sources possibles, toutes les questions numérotées, problème, résumé. */
  conditionSources: ConditionSource[];
  allQuestions: ConditionSource[];
  conditionIssue: string | null;
  conditionSummary: string | null;
  onChange: (question: BuilderQuestion) => void;
  onDuplicate: () => void;
  onRemove: () => void;
}

/**
 * Carte de question en accordéon. Repliée : une ligne (numéro, type, intitulé, caractère obligatoire),
 * pratique pour relire et réordonner. Dépliée : propriétés communes, paramètres du type, aide contextuelle.
 */
export const QuestionCard = ({
  question,
  number,
  locked,
  expanded,
  onToggle,
  autoFocus = false,
  issues,
  conditionSources,
  allQuestions,
  conditionIssue,
  conditionSummary,
  onChange,
  onDuplicate,
  onRemove,
}: QuestionCardProps) => {
  const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({
    id: question.id,
    data: { kind: 'question' },
    disabled: locked,
  });
  const bodyId = useId();

  const update = (patch: Partial<BuilderQuestion>) => onChange({ ...question, ...patch });
  const label = plainText(question.label);
  // Aide par défaut (choix multiple) et aide réellement vue par le participant
  const defaultHelp = effectiveHelpText({ ...question, helpText: null });
  const shownHelp = effectiveHelpText(question);

  return (
    <Card
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      $dragging={isDragging}
      $invalid={issues.length > 0}
      $expanded={expanded}
      aria-label={`Question ${number}`}
    >
      <Head $expanded={expanded}>
        {!locked && (
          <Handle ref={setActivatorNodeRef} type="button" aria-label={`Déplacer la question ${number}`} {...attributes} {...listeners}>
            <Icon name="drag-vertical" size={20} />
          </Handle>
        )}
        <Summary type="button" aria-expanded={expanded} aria-controls={bodyId} onClick={onToggle}>
          <NumberTag>{number}.</NumberTag>
          <TypeBadge title={QUESTION_TYPE_LABELS[question.type]}>
            <Icon name={QUESTION_TYPE_ICONS[question.type]} size={16} />
            <span>{QUESTION_TYPE_LABELS[question.type]}</span>
          </TypeBadge>
          <SummaryLabel $empty={label === ''}>{label || 'Question sans intitulé'}</SummaryLabel>
          {conditionSummary && (
            <ConditionBadge $warning={conditionIssue !== null} title={conditionIssue ?? conditionSummary}>
              <Icon name={conditionIssue ? 'alert-circle-outline' : 'link-variant'} size={14} />
              <span>{conditionSummary}</span>
            </ConditionBadge>
          )}
          {!expanded && <Requirement>{question.required ? 'Obligatoire' : 'Facultative'}</Requirement>}
          <Chevron $expanded={expanded} aria-hidden="true">
            <Icon name="chevron-down" size={20} />
          </Chevron>
        </Summary>
        {!locked && (
          <Tools>
            <IconButton name="content-copy" size="sm" variant="ghost" aria-label={`Dupliquer la question ${number}`} onClick={onDuplicate} />
            <IconButton name="trash-can-outline" size="sm" variant="ghost" aria-label={`Supprimer la question ${number}`} onClick={onRemove} />
          </Tools>
        )}
      </Head>

      {issues.length > 0 && (
        <Issues role="alert">
          {issues.map((issue) => (
            <li key={issue}>{issue}</li>
          ))}
        </Issues>
      )}

      {expanded && (
      <Body id={bodyId}>
        <Main>
          {locked ? (
            <ReadOnly>
              <span>Intitulé de la question</span>
              <div>
                <InlineMarkdown source={question.label} />
              </div>
            </ReadOnly>
          ) : (
            <MarkdownEditor
              label="Intitulé de la question"
              required
              rows={2}
              value={question.label}
              maxLength={500}
              autoFocus={autoFocus}
              actions={LABEL_ACTIONS}
              hint="Markdown en ligne : **gras**, *italique*, ~~barré~~, ==surligné==, `code`, [lien](https://…)."
              onChange={(value) => update({ label: value })}
            />
          )}
          <Line>
            <QuestionTypeSelect value={question.type} disabled={locked} onChange={(type) => update({ type, config: defaultConfig(type) })} />
            <ToggleField label="Réponse obligatoire" checked={question.required} disabled={locked} onChange={(e) => update({ required: e.target.checked })} />
          </Line>

          <Fieldset disabled={locked}>
            <legend>Paramètres</legend>
            <ConfigEditor type={question.type} config={question.config} onChange={(config) => update({ config })} disabled={locked} />
          </Fieldset>

          <Fieldset disabled={locked}>
            <legend>Affichage conditionnel</legend>
            <DisplayConditionEditor
              condition={question.displayCondition}
              sources={conditionSources}
              allQuestions={allQuestions}
              number={number}
              issue={conditionIssue}
              disabled={locked}
              onChange={(displayCondition) => update({ displayCondition })}
            />
          </Fieldset>
        </Main>

        <Aside>
          <Fieldset disabled={locked}>
            <legend>Aide contextuelle (facultative)</legend>
            <TextField
              label="Titre de l'encart"
              placeholder="Ex. : À savoir"
              maxLength={80}
              value={question.helpTitle}
              onChange={(e) => update({ helpTitle: e.target.value })}
              hint="80 caractères maximum. Ignoré sans texte d'aide."
            />
            {locked ? (
              question.helpText.trim() !== '' && (
                <ReadOnly>
                  <span>Texte d'aide</span>
                  <MarkdownBlock source={question.helpText} />
                </ReadOnly>
              )
            ) : (
              <MarkdownEditor
                label="Texte d'aide"
                rows={3}
                value={question.helpText}
                actions={HELP_ACTIONS}
                placeholder={defaultHelp ? `Par défaut : « ${defaultHelp} »` : undefined}
                onChange={(value) => update({ helpText: value })}
                hint={defaultHelp ? 'Markdown. Laissé vide, l\'aide par défaut du choix multiple est affichée.' : 'Markdown : listes, liens, gras… Les retours à la ligne sont conservés.'}
              />
            )}
          </Fieldset>
          {shownHelp && (
            <Preview>
              <span>Aperçu participant{question.helpText.trim() === '' ? ' (aide par défaut)' : ''}</span>
              <HelpBox title={question.helpText.trim() !== '' ? question.helpTitle.trim() || null : null}>
                <MarkdownBlock source={shownHelp} />
              </HelpBox>
            </Preview>
          )}
        </Aside>
      </Body>
      )}
    </Card>
  );
};

const Card = styled.article<{ $dragging: boolean; $invalid: boolean; $expanded: boolean }>`
  position: relative;
  background: ${({ theme }) => theme.colors.background.surface};
  border: 1px solid ${({ theme, $invalid, $expanded }) => ($invalid ? theme.colors.danger.base : $expanded ? theme.colors.primary.base : theme.colors.border.default)};
  border-radius: ${({ theme }) => theme.radii.lg};
  box-shadow: ${({ theme, $dragging, $expanded }) => ($dragging ? theme.shadows.lg : $expanded ? theme.shadows.md : 'none')};
  opacity: ${({ $dragging }) => ($dragging ? 0.85 : 1)};
  z-index: ${({ $dragging }) => ($dragging ? 10 : 'auto')};
  transition:
    border-color 0.15s ease,
    box-shadow 0.15s ease;

  &:hover {
    border-color: ${({ theme, $invalid, $expanded }) => ($invalid ? theme.colors.danger.base : $expanded ? theme.colors.primary.base : theme.colors.border.hover)};
  }

  fieldset {
    margin: 0;
    padding: 0;
    border: 0;
  }
`;

const Head = styled.header<{ $expanded: boolean }>`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1]};
  padding: ${({ theme }) => `${theme.spacing[1]} ${theme.spacing[2]}`};
  border-bottom: 1px solid ${({ theme, $expanded }) => ($expanded ? theme.colors.border.default : 'transparent')};
`;

const Summary = styled.button`
  display: flex;
  flex: 1;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
  min-width: 0;
  min-height: 44px;
  padding: ${({ theme }) => `${theme.spacing[1]} ${theme.spacing[2]}`};
  border: 0;
  border-radius: ${({ theme }) => theme.radii.md};
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;

  &:hover {
    background: ${({ theme }) => theme.colors.background.surfaceHover};
  }

  &:focus-visible {
    outline: 2px solid ${({ theme }) => theme.colors.primary.base};
    outline-offset: 1px;
  }
`;

const TypeBadge = styled.span`
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1]};
  padding: ${({ theme }) => `2px ${theme.spacing[2]}`};
  border-radius: ${({ theme }) => theme.radii.pill};
  background: ${({ theme }) => theme.colors.primary.subtle};
  color: ${({ theme }) => theme.colors.primary.base};
  font-size: 0.75rem;
  font-weight: 600;

  @media (max-width: 600px) {
    span {
      display: none;
    }
  }
`;

const SummaryLabel = styled.span<{ $empty: boolean }>`
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
  font-style: ${({ $empty }) => ($empty ? 'italic' : 'normal')};
  color: ${({ theme, $empty }) => ($empty ? theme.colors.text.muted : theme.colors.text.heading)};
`;

const ConditionBadge = styled.span<{ $warning: boolean }>`
  display: inline-flex;
  flex-shrink: 1;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1]};
  min-width: 0;
  max-width: 40%;
  padding: ${({ theme }) => `2px ${theme.spacing[2]}`};
  border-radius: ${({ theme }) => theme.radii.pill};
  background: ${({ theme, $warning }) => ($warning ? theme.colors.danger.subtle : theme.colors.background.surfaceHover)};
  color: ${({ theme, $warning }) => ($warning ? theme.colors.danger.dark : theme.colors.text.body)};
  font-size: 0.75rem;

  span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  @media (max-width: 600px) {
    span {
      display: none;
    }
  }
`;

const Requirement = styled.span`
  flex-shrink: 0;
  font-size: 0.75rem;
  color: ${({ theme }) => theme.colors.text.muted};

  @media (max-width: 600px) {
    display: none;
  }
`;

const Chevron = styled.span<{ $expanded: boolean }>`
  display: inline-flex;
  flex-shrink: 0;
  color: ${({ theme }) => theme.colors.text.muted};
  transform: rotate(${({ $expanded }) => ($expanded ? '180deg' : '0deg')});
  transition: transform 0.15s ease;

  @media (prefers-reduced-motion: reduce) {
    transition: none;
  }
`;

const Handle = styled.button`
  display: inline-flex;
  padding: ${({ theme }) => theme.spacing[1]};
  border: 0;
  border-radius: ${({ theme }) => theme.radii.sm};
  background: transparent;
  color: ${({ theme }) => theme.colors.text.muted};
  cursor: grab;
  touch-action: none;

  &:active {
    cursor: grabbing;
  }

  &:hover,
  &:focus-visible {
    background: ${({ theme }) => theme.colors.background.surfaceHover};
  }
`;

const NumberTag = styled.strong`
  flex-shrink: 0;
  min-width: 1.5em;
  font-size: 0.95rem;
  color: ${({ theme }) => theme.colors.primary.base};
`;

const Tools = styled.div`
  display: flex;
  flex-shrink: 0;
`;

const Issues = styled.ul`
  margin: 0;
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[6]}`};
  background: ${({ theme }) => theme.colors.danger.subtle};
  color: ${({ theme }) => theme.colors.danger.dark};
  font-size: 0.875rem;
`;

const Body = styled.div`
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
  gap: ${({ theme }) => theme.spacing[5]};
  padding: ${({ theme }) => theme.spacing[4]};

  @media (max-width: 900px) {
    grid-template-columns: 1fr;
  }
`;

const Main = styled.div`
  min-width: 0;
`;

const Aside = styled.div`
  min-width: 0;
`;

const Line = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0 ${({ theme }) => theme.spacing[5]};

  & > :first-child {
    min-width: 200px;
  }
`;

const Fieldset = styled.fieldset`
  legend {
    margin-bottom: ${({ theme }) => theme.spacing[2]};
    font-size: 0.8rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: ${({ theme }) => theme.colors.text.muted};
  }

  & > * + * {
    margin-top: ${({ theme }) => theme.spacing[2]};
  }
`;

const ReadOnly = styled.div`
  margin-bottom: ${({ theme }) => theme.spacing[3]};

  & > span {
    display: block;
    margin-bottom: ${({ theme }) => theme.spacing[1]};
    font-size: 0.875rem;
    font-weight: 600;
    color: ${({ theme }) => theme.colors.text.heading};
  }
`;

const Preview = styled.div`
  margin-top: ${({ theme }) => theme.spacing[2]};

  & > span {
    display: block;
    margin-bottom: ${({ theme }) => theme.spacing[1]};
    font-size: 0.75rem;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;
