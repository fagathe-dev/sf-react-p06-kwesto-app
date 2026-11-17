import type { Question } from '@/types';
import { describe, expect, it } from 'vitest';
import { canBeConditionSource, isSatisfied, operatorsFor, pruneHidden, visibleQuestionIds } from './conditionEvaluator';

const q = (id: string, type: Question['type'], position: number, displayCondition: Question['displayCondition'] = null, config: Question['config'] = {} as Question['config']): Question => ({
  id,
  sectionId: null,
  type,
  label: id,
  helpTitle: null,
  helpText: null,
  required: true,
  position,
  config,
  displayCondition,
});

const options = [
  { id: 'mr', label: 'Monsieur' },
  { id: 'mme', label: 'Madame' },
];

describe('conditionEvaluator', () => {
  const civility = q('civility', 'CHOICE', 1, null, { widget: 'radio', options });
  // « Avez-vous changé de nom ? » (Oui / Non), seulement pour Madame
  const changed = q('changed', 'BOOLEAN', 2, { sourceQuestionId: 'civility', operator: 'EQUALS', value: 'mme' }, { preset: 'YES_NO', trueLabel: 'Oui', falseLabel: 'Non', widget: 'buttons', defaultValue: null });
  // « Nom de naissance », seulement si Oui (cascade)
  const maiden = q('maiden', 'TEXT', 3, { sourceQuestionId: 'changed', operator: 'EQUALS', value: true });
  const all = [civility, changed, maiden];

  it('Civilité = Madame affiche la question suivante ; Monsieur la masque, en cascade', () => {
    expect([...visibleQuestionIds(all, { civility: 'mme', changed: 'true' })]).toEqual(['civility', 'changed', 'maiden']);
    expect([...visibleQuestionIds(all, { civility: 'mme', changed: 'false' })]).toEqual(['civility', 'changed']);
    expect([...visibleQuestionIds(all, { civility: 'mr', changed: 'true' })]).toEqual(['civility']);
  });

  it('source sans réponse : condition fausse, même avec « n\'est pas »', () => {
    const notNo = q('maiden', 'TEXT', 3, { sourceQuestionId: 'changed', operator: 'NOT_EQUALS', value: false });
    expect([...visibleQuestionIds([civility, changed, notNo], { civility: 'mme' })]).toEqual(['civility', 'changed']);
  });

  it('efface les réponses des questions redevenues masquées', () => {
    expect(pruneHidden(all, { civility: 'mr', changed: 'true', maiden: 'Durand' })).toEqual({ civility: 'mr' });
  });

  it('sources autorisées : Oui / Non et choix unique (radio ou liste) seulement', () => {
    expect(canBeConditionSource({ type: 'BOOLEAN', config: {} })).toBe(true);
    expect(canBeConditionSource({ type: 'CHOICE', config: { widget: 'radio', options } })).toBe(true);
    expect(canBeConditionSource({ type: 'CHOICE', config: { widget: 'select', options } })).toBe(true);
    expect(canBeConditionSource({ type: 'CHOICE', config: { widget: 'checkbox', options } })).toBe(false);
    expect(canBeConditionSource({ type: 'NUMBER', config: {} })).toBe(true);
    expect(canBeConditionSource({ type: 'RANGE', config: {} })).toBe(true);
    expect(operatorsFor({ type: 'RANGE', config: {} })).toEqual(['EQUALS', 'NOT_EQUALS', 'LESS_THAN', 'LESS_OR_EQUAL', 'GREATER_THAN', 'GREATER_OR_EQUAL']);
    for (const type of ['TEXT', 'DATE', 'FILE'] as const) {
      expect(canBeConditionSource({ type, config: {} })).toBe(false);
    }
    expect(operatorsFor({ type: 'BOOLEAN', config: {} })).toEqual(['EQUALS', 'NOT_EQUALS']);
    expect(operatorsFor({ type: 'CHOICE', config: { widget: 'radio', options } })).toEqual(['EQUALS', 'NOT_EQUALS', 'IN']);
  });

  it('opérateurs : choix unique et Oui / Non', () => {
    expect(isSatisfied({ sourceQuestionId: 's', operator: 'IN', value: ['a', 'b'] }, 'CHOICE', ['b'])).toBe(true);
    expect(isSatisfied({ sourceQuestionId: 's', operator: 'NOT_EQUALS', value: 'a' }, 'CHOICE', ['b'])).toBe(true);
    expect(isSatisfied({ sourceQuestionId: 's', operator: 'EQUALS', value: true }, 'BOOLEAN', 'true')).toBe(true);
    expect(isSatisfied({ sourceQuestionId: 's', operator: 'NOT_EQUALS', value: true }, 'BOOLEAN', 'false')).toBe(true);
    // Source d'un type non autorisé : jamais satisfaite
    expect(isSatisfied({ sourceQuestionId: 's', operator: 'EQUALS', value: 'x' }, 'TEXT', 'x')).toBe(false);
  });

  it('comparaisons numériques : note inférieure à 5, chiffres avec virgule', () => {
    const below5 = { sourceQuestionId: 's', operator: 'LESS_THAN' as const, value: 5 };
    expect(isSatisfied(below5, 'RANGE', '4')).toBe(true);
    expect(isSatisfied(below5, 'RANGE', '5')).toBe(false);
    expect(isSatisfied({ ...below5, operator: 'LESS_OR_EQUAL' }, 'RANGE', '5')).toBe(true);
    expect(isSatisfied({ ...below5, operator: 'GREATER_THAN', value: 2.5 }, 'NUMBER', '2,5')).toBe(false);
    expect(isSatisfied({ ...below5, operator: 'GREATER_OR_EQUAL', value: 2.5 }, 'NUMBER', '2,5')).toBe(true);
    expect(isSatisfied({ ...below5, operator: 'NOT_EQUALS', value: 3 }, 'NUMBER', '1 200')).toBe(true);
    expect(isSatisfied(below5, 'NUMBER', 'abc')).toBe(false);

    // Cascade réelle : Q2 « Comment améliorer ? » seulement si la note de Q1 est inférieure à 5
    const note = q('note', 'RANGE', 1, null, { min: 1, max: 10, step: 1, defaultValue: null });
    const improve = q('improve', 'TEXT', 2, { sourceQuestionId: 'note', operator: 'LESS_THAN', value: 5 });
    expect([...visibleQuestionIds([note, improve], { note: '3' })]).toEqual(['note', 'improve']);
    expect([...visibleQuestionIds([note, improve], { note: '8' })]).toEqual(['note']);
  });
});
