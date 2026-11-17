import { canBeConditionSource, operatorsFor } from '@/pages/participant/conditionEvaluator';
import { formatRangeValue, type BooleanConfig, type ChoiceConfig, type ConditionOperator, type DisplayCondition, type NumberConfig, type QuestionType, type RangeConfig } from '@/types';
import type { BuilderQuestion, BuilderState } from './builderModel';
import { orderedQuestions } from './builderModel';

/**
 * Affichage conditionnel dans le builder (specs_fonc §3.5) : libellés, valeurs par défaut, contrôles.
 * Règle : une question ne peut dépendre que d'une question précédente Oui / Non (BOOLEAN), à choix unique
 * (CHOICE en boutons radio ou liste déroulante), Chiffres (NUMBER) ou Échelle (RANGE).
 */

export const SOURCE_RULE = 'Une condition ne peut porter que sur une question « Oui / Non », à choix unique, « Chiffres » ou « Échelle » placée avant.';

type SourceLike = Pick<BuilderQuestion, 'type' | 'config'>;

const isNumeric = (type: QuestionType) => type === 'NUMBER' || type === 'RANGE';

export const operatorLabel = (operator: ConditionOperator, sourceType?: QuestionType): string => {
  const numeric = sourceType !== undefined && isNumeric(sourceType);
  switch (operator) {
    case 'EQUALS':
      return numeric ? 'est égal à' : 'est';
    case 'NOT_EQUALS':
      return numeric ? 'est différent de' : 'n\'est pas';
    case 'IN':
      return 'est l\'une de';
    case 'LESS_THAN':
      return 'est inférieur à';
    case 'LESS_OR_EQUAL':
      return 'est inférieur ou égal à';
    case 'GREATER_THAN':
      return 'est supérieur à';
    case 'GREATER_OR_EQUAL':
      return 'est supérieur ou égal à';
  }
};

/** Valeurs proposées pour une source Échelle (liste personnalisée ou crans de l'intervalle). */
export const rangeScale = (config: RangeConfig): number[] => {
  if (config.values && config.values.length > 0) return config.values;
  if (!(config.step > 0) || !(config.min < config.max)) return [];
  const count = Math.round((config.max - config.min) / config.step);
  return count > 200 ? [] : Array.from({ length: count + 1 }, (_, i) => Math.round((config.min + i * config.step) * 1e6) / 1e6);
};

/** Valeur initiale proposée selon le type de la source et l'opérateur. */
export const defaultValue = (source: SourceLike, operator: ConditionOperator): DisplayCondition['value'] => {
  if (source.type === 'BOOLEAN') return true;
  if (source.type === 'RANGE') {
    // Milieu de l'échelle : « note inférieure à 5 » sur une échelle de 0 à 10
    const scale = rangeScale(source.config as RangeConfig);
    return scale[Math.floor(scale.length / 2)] ?? (source.config as RangeConfig).min;
  }
  if (source.type === 'NUMBER') return (source.config as NumberConfig).min ?? 0;
  const first = (source.config as ChoiceConfig).options[0]?.id ?? '';
  return operator === 'IN' ? (first ? [first] : []) : first;
};

export const newCondition = (source: BuilderQuestion): DisplayCondition => {
  const operator = operatorsFor(source)[0] ?? 'EQUALS';
  return { sourceQuestionId: source.id, operator, value: defaultValue(source, operator) };
};

/** Questions pouvant servir de source : placées avant, Oui / Non, à choix unique, Chiffres ou Échelle. */
export const eligibleSources = (state: BuilderState, questionId: string): BuilderQuestion[] => {
  const ordered = orderedQuestions(state);
  const index = ordered.findIndex((q) => q.id === questionId);
  return index <= 0 ? [] : ordered.slice(0, index).filter(canBeConditionSource);
};

/** Source autorisée et valeur de la bonne forme (sinon l'API refuserait la structure). */
export const hasValidShape = (condition: DisplayCondition, source: SourceLike): boolean => {
  const { value, operator } = condition;
  if (!operatorsFor(source).includes(operator)) return false;
  if (source.type === 'BOOLEAN') return typeof value === 'boolean';
  if (isNumeric(source.type)) return typeof value === 'number' && Number.isFinite(value);
  return operator === 'IN' ? Array.isArray(value) && value.length > 0 : typeof value === 'string' && value !== '';
};

/** Problème de la condition d'une question, null si elle est correcte (ou absente). */
export const conditionIssue = (state: BuilderState, question: BuilderQuestion): string | null => {
  const condition = question.displayCondition;
  if (!condition) return null;

  const ordered = orderedQuestions(state);
  const source = ordered.find((q) => q.id === condition.sourceQuestionId);
  if (!source) return 'La question dont dépend cette condition n\'existe plus.';
  if (ordered.indexOf(source) >= ordered.indexOf(question)) return 'La question source doit être placée avant celle-ci.';
  if (!canBeConditionSource(source)) return SOURCE_RULE;
  if (!hasValidShape(condition, source)) return 'Complétez la valeur de la condition.';

  if (source.type === 'CHOICE') {
    const ids = (source.config as ChoiceConfig).options.map((o) => o.id);
    const values = Array.isArray(condition.value) ? condition.value : [String(condition.value)];
    if (values.some((v) => !ids.includes(v))) return 'La condition vise une option qui n\'existe plus.';
  }

  return null;
};

/** Valeur lisible de la condition (libellé d'option, libellé vrai / faux, date au format français). */
const valueLabel = (condition: DisplayCondition, source: BuilderQuestion): string => {
  const { value } = condition;
  switch (source.type) {
    case 'CHOICE': {
      const options = (source.config as ChoiceConfig).options;
      const label = (id: string) => options.find((o) => o.id === id)?.label || '?';
      return Array.isArray(value) ? value.map(label).join(' ou ') : label(String(value));
    }
    case 'BOOLEAN': {
      const config = source.config as BooleanConfig;
      return value === true ? config.trueLabel : config.falseLabel;
    }
    case 'RANGE':
      return typeof value === 'number' ? formatRangeValue(value, (source.config as RangeConfig).unit) : String(value);
    case 'NUMBER': {
      const unit = (source.config as NumberConfig).unit;
      return typeof value === 'number' ? `${value.toLocaleString('fr-FR')}${unit ? `\u00A0${unit}` : ''}` : String(value);
    }
    default:
      return String(value);
  }
};

/** Résumé court : « Si Q1 est « Madame » », « Si Q2 est inférieur à « 5/10 » ». */
export const describeCondition = (condition: DisplayCondition, source: BuilderQuestion | undefined, sourceNumber: number | undefined): string => {
  if (!source) return 'Conditionnelle — question source introuvable';
  return `Si Q${sourceNumber ?? '?'} ${operatorLabel(condition.operator, source.type)} « ${valueLabel(condition, source)} »`;
};

/** Questions dont la source n'est plus placée avant elles (ex. après un glisser-déposer). */
export const orderViolations = (state: BuilderState): BuilderQuestion[] => {
  const ordered = orderedQuestions(state);
  const index = new Map(ordered.map((q, i) => [q.id, i]));
  return ordered.filter((q) => {
    const sourceIndex = q.displayCondition ? index.get(q.displayCondition.sourceQuestionId) : undefined;
    return sourceIndex !== undefined && sourceIndex >= (index.get(q.id) ?? 0);
  });
};

/** Retire les conditions qui ne respectent plus l'ordre. */
export const withoutOrderViolations = (state: BuilderState): BuilderState => {
  const broken = new Set(orderViolations(state).map((q) => q.id));
  return broken.size === 0 ? state : { ...state, questions: state.questions.map((q) => (broken.has(q.id) ? { ...q, displayCondition: null } : q)) };
};

/** Retire les conditions qui dépendent d'une question (supprimée, ou dont le type a changé). */
export const withoutConditionsOn = (state: BuilderState, sourceId: string): BuilderState => ({
  ...state,
  questions: state.questions.map((q) => (q.displayCondition?.sourceQuestionId === sourceId ? { ...q, displayCondition: null } : q)),
});

export const dependentsOf = (state: BuilderState, sourceId: string): BuilderQuestion[] => state.questions.filter((q) => q.displayCondition?.sourceQuestionId === sourceId);
