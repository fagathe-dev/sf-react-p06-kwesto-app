import { describe, expect, it } from 'vitest';
import { addSection, moveQuestion, newQuestion, numbering, removeSection, ROOT, toPayload, type BuilderState } from './builderModel';

const labelled = (label: string, sectionId: string | null = null) => ({ ...newQuestion('TEXT', sectionId), label });

describe('builderModel', () => {
  it('numérote en continu sur tout le questionnaire, section par section', () => {
    const s1 = { id: 's1', title: 'Un', description: '' };
    const s2 = { id: 's2', title: 'Deux', description: '' };
    // Ordre global volontairement mélangé : la numérotation suit l'ordre des sections
    const state: BuilderState = { sections: [s1, s2], questions: [labelled('B', 's2'), labelled('A', 's1'), labelled('C', 's2')] };

    const numbers = numbering(state);
    const byLabel = Object.fromEntries(state.questions.map((q) => [q.label, numbers.get(q.id)]));

    expect(byLabel).toEqual({ A: 1, B: 2, C: 3 });
    expect(toPayload(state).questions.map((q) => q.label)).toEqual(['A', 'B', 'C']);
  });

  it('renumérote une question déplacée d\'une section à l\'autre', () => {
    const state: BuilderState = {
      sections: [{ id: 's1', title: 'Un', description: '' }, { id: 's2', title: 'Deux', description: '' }],
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
