import type { Answers, AnswerValue, ChoiceConfig, ConditionOperator, DisplayCondition, Question, QuestionType } from '@/types';

/**
 * Affichage conditionnel côté navigateur (specs_fonc §3.5), miroir de FormsCore\Service\ConditionEvaluatorService :
 * mêmes sources, mêmes opérateurs, même règle « source sans réponse = condition fausse », même cascade.
 * Le serveur reste seul juge à la soumission.
 */

type SourceLike = { type: QuestionType; config: unknown };

const NUMERIC_OPERATORS: ConditionOperator[] = ['EQUALS', 'NOT_EQUALS', 'LESS_THAN', 'LESS_OR_EQUAL', 'GREATER_THAN', 'GREATER_OR_EQUAL'];

/**
 * Opérateurs autorisés pour une question source. Sources possibles : Oui / Non (BOOLEAN), choix unique (CHOICE
 * en boutons radio ou liste déroulante), Chiffres (NUMBER) et Échelle (RANGE). Aucun opérateur (= source
 * interdite) pour TEXT, DATE, FILE et un choix multiple.
 */
export const operatorsFor = (source: SourceLike): ConditionOperator[] => {
  if (source.type === 'BOOLEAN') return ['EQUALS', 'NOT_EQUALS'];
  if (source.type === 'CHOICE' && (source.config as ChoiceConfig).widget !== 'checkbox') return ['EQUALS', 'NOT_EQUALS', 'IN'];
  if (source.type === 'NUMBER' || source.type === 'RANGE') return NUMERIC_OPERATORS;
  return [];
};

/** Nombre saisi (virgule décimale et espaces de milliers acceptés), NaN sinon. */
const toNumber = (raw: string | number | boolean | string[]): number => {
  if (typeof raw === 'number') return raw;
  if (typeof raw !== 'string') return Number.NaN;
  const normalized = raw.trim().replace(/[\s\u00A0\u202F]/g, '').replace(',', '.');
  return /^[+-]?(\d+(\.\d+)?|\.\d+)$/.test(normalized) ? Number(normalized) : Number.NaN;
};

const compare = (operator: ConditionOperator, answer: number, expected: number): boolean => {
  if (Number.isNaN(answer) || Number.isNaN(expected)) return false;
  const equal = Math.abs(answer - expected) < 1e-9;
  switch (operator) {
    case 'EQUALS':
      return equal;
    case 'NOT_EQUALS':
      return !equal;
    case 'LESS_THAN':
      return answer < expected && !equal;
    case 'LESS_OR_EQUAL':
      return answer < expected || equal;
    case 'GREATER_THAN':
      return answer > expected && !equal;
    case 'GREATER_OR_EQUAL':
      return answer > expected || equal;
    default:
      return false;
  }
};

export const canBeConditionSource = (source: SourceLike): boolean => operatorsFor(source).length > 0;

const isBlank = (value: AnswerValue | undefined): boolean => value === undefined || (Array.isArray(value) ? value.length === 0 : value.trim() === '');

/** La réponse à la source satisfait-elle la condition ? Toujours faux si la source n'a pas de réponse. */
export const isSatisfied = (condition: DisplayCondition, sourceType: QuestionType, answer: AnswerValue | undefined): boolean => {
  if (isBlank(answer)) return false;
  const { operator, value } = condition;

  switch (sourceType) {
    case 'CHOICE': {
      const selected = Array.isArray(answer) ? answer : [String(answer)];
      if (operator === 'IN') return (Array.isArray(value) ? value : [String(value)]).some((id) => selected.includes(id));
      if (operator === 'EQUALS') return selected.includes(String(value));
      if (operator === 'NOT_EQUALS') return !selected.includes(String(value));
      return false;
    }
    case 'BOOLEAN': {
      const equal = (answer === 'true') === (value === true || value === 'true');
      return operator === 'EQUALS' ? equal : operator === 'NOT_EQUALS' ? !equal : false;
    }
    case 'NUMBER':
    case 'RANGE':
      return compare(operator, toNumber(Array.isArray(answer) ? '' : answer!), toNumber(value));
    default:
      return false;
  }
};

/**
 * Questions visibles, dans l'ordre des positions. Une question dont la source est masquée est masquée (cascade).
 */
export const visibleQuestionIds = (orderedQuestions: Question[], answers: Answers): Set<string> => {
  const byId = new Map(orderedQuestions.map((q) => [q.id, q]));
  const visible = new Set<string>();

  for (const question of orderedQuestions) {
    const condition = question.displayCondition;
    if (!condition) {
      visible.add(question.id);
      continue;
    }

    const source = byId.get(condition.sourceQuestionId);
    if (source && visible.has(source.id) && isSatisfied(condition, source.type, answers[source.id])) {
      visible.add(question.id);
    }
  }

  return visible;
};

/** Réponses des seules questions visibles : la valeur d'une question qui redevient masquée est effacée. */
export const pruneHidden = (orderedQuestions: Question[], answers: Answers): Answers => {
  const visible = visibleQuestionIds(orderedQuestions, answers);
  return Object.fromEntries(Object.entries(answers).filter(([id]) => visible.has(id)));
};
