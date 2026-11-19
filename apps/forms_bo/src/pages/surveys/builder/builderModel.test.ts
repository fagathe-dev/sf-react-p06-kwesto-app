import { describe, expect, it } from 'vitest';
import { addSection, moveQuestion, newQuestion, numbering, removeSection, ROOT, toPayload, type BuilderSection, type BuilderState } from './builderModel';
import { eligibleSectionSources, sectionConditionIssue, withoutConditionsOn, withoutOrderViolations } from './conditions';

const labelled = (label: string, sectionId: string | null = null) => ({ ...newQuestion('TEXT', sectionId), label });
const section = (id: string, title: string): BuilderSection => ({ id, title, description: '', displayCondition: null });

describe('sections conditionnelles', () => {
  // « Souhaitez-vous être recontacté(e) ? » (section 1) → section « Coordonnées » si Oui
  const callback = { ...newQuestion('BOOLEAN', 's1'), id: 'callback', label: 'Recontacter ?' };
  const name = { ...labelled('Nom', 's2'), id: 'name' };
  const contact: BuilderSection = { ...section('s2', 'Coordonnées'), displayCondition: { sourceQuestionId: 'callback', operator: 'EQUALS', value: true } };
  const state: BuilderState = { sections: [section('s1', 'Avis'), contact], questions: [callback, name] };

  it('propose les questions des sections précédentes et envoie la condition', () => {
    expect(eligibleSectionSources(state, 's1')).toEqual([]);
    expect(eligibleSectionSources(state, 's2').map((q) => q.id)).toEqual(['callback']);
    expect(sectionConditionIssue(state, contact)).toBeNull();
    expect(toPayload(state).sections[1]!.displayCondition).toEqual({ sourceQuestionId: 'callback', operator: 'EQUALS', value: true });
  });

  it('retire la condition quand la source passe dans la section ou est supprimée', () => {
    const moved = moveQuestion(state, 'callback', 's2', null);
    expect(sectionConditionIssue(moved, moved.sections[1]!)).toMatch(/section précédente/);
    expect(withoutOrderViolations(moved).sections[1]!.displayCondition).toBeNull();
    expect(withoutConditionsOn(state, 'callback').sections[1]!.displayCondition).toBeNull();
  });
});

describe('builderModel', () => {
  it('numérote en continu sur tout le questionnaire, section par section', () => {
    const s1 = section('s1', 'Un');
    const s2 = section('s2', 'Deux');
    // Ordre global volontairement mélangé : la numérotation suit l'ordre des sections
    const state: BuilderState = { sections: [s1, s2], questions: [labelled('B', 's2'), labelled('A', 's1'), labelled('C', 's2')] };

    const numbers = numbering(state);
    const byLabel = Object.fromEntries(state.questions.map((q) => [q.label, numbers.get(q.id)]));

    expect(byLabel).toEqual({ A: 1, B: 2, C: 3 });
    expect(toPayload(state).questions.map((q) => q.label)).toEqual(['A', 'B', 'C']);
  });

  it('renumérote une question déplacée d\'une section à l\'autre', () => {
    const state: BuilderState = {
      sections: [section('s1', 'Un'), section('s2', 'Deux')],
      questions: [labelled('A', 's1'), labelled('B', 's1'), labelled('C', 's2')],
    };
    const b = state.questions[1]!;
    const c = state.questions[2]!;

    // B passe en section 2, avant C
    const moved = moveQuestion(state, b.id, 's2', c.id);
    const numbers = numbering(moved);

    expect(moved.questions.find((q) => q.id === b.id)?.sectionId).toBe('s2');
    expect(numbers.get(b.id)).toBe(2);
    expect(numbers.get(c.id)).toBe(3);
  });

  it('rattache toutes les questions à la première section créée', () => {
    const state: BuilderState = { sections: [], questions: [labelled('A'), labelled('B')] };
    const withSection = addSection(state);

    expect(withSection.sections).toHaveLength(1);
    expect(withSection.questions.every((q) => q.sectionId === withSection.sections[0]!.id)).toBe(true);
  });

  it('revient à une page unique quand la dernière section est supprimée', () => {
    const one = addSection({ sections: [], questions: [labelled('A')] });
    const none = removeSection(one, one.sections[0]!.id);

    expect(none.sections).toHaveLength(0);
    expect(toPayload(none).questions[0]!.sectionId).toBeNull();
  });

  it('réordonne dans une page unique', () => {
    const state: BuilderState = { sections: [], questions: [labelled('A'), labelled('B'), labelled('C')] };
    const moved = moveQuestion(state, state.questions[2]!.id, ROOT, state.questions[0]!.id);

    expect(moved.questions.map((q) => q.label)).toEqual(['C', 'A', 'B']);
  });
});
