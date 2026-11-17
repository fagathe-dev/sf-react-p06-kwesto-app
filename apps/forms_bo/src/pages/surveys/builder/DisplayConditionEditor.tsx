import { operatorsFor } from '@/pages/participant/conditionEvaluator';
import { formatRangeValue, type BooleanConfig, type ChoiceConfig, type ConditionOperator, type DisplayCondition, type NumberConfig, type RangeConfig } from '@/types';
import { HelpBox } from '@/ui/HelpBox';
import { plainText } from '@/ui/markdownText';
import { CheckField, ChoiceField, TextField, ToggleField } from 'core-react/ui';
import { useState } from 'react';
import { styled } from 'styled-components';
import type { BuilderQuestion } from './builderModel';
import { defaultValue, describeCondition, newCondition, operatorLabel, rangeScale } from './conditions';

export interface ConditionSource {
  question: BuilderQuestion;
  number: number;
}

interface DisplayConditionEditorProps {
  condition: DisplayCondition | null;
  /** Questions précédentes pouvant servir de source (Oui / Non ou choix unique), avec leur numéro. */
  sources: ConditionSource[];
  /** Toutes les questions numérotées : la source actuelle peut ne plus être éligible (déplacée, type changé). */
  allQuestions: ConditionSource[];
  number: number;
  issue: string | null;
  disabled: boolean;
  onChange: (condition: DisplayCondition | null) => void;
}

const optionLabel = (label: string, index: number) => label.trim() || `Option ${index + 1}`;

/**
 * « Afficher cette question uniquement si… » (specs_fonc §3.5) : une condition par question, sur une
 * question précédente. Masquée, la question n'est pas obligatoire et sa réponse n'est pas enregistrée.
 */
export const DisplayConditionEditor = ({ condition, sources, allQuestions, number, issue, disabled, onChange }: DisplayConditionEditorProps) => {
  const current = condition ? allQuestions.find((s) => s.question.id === condition.sourceQuestionId) : undefined;
  const firstSource = sources[0];

  if (!condition && !firstSource) {
    return (
      <Hint>
        {number === 1
          ? 'La première question est toujours affichée : elle ne peut pas dépendre d\'une autre.'
          : 'Aucune question précédente ne peut servir de condition : seules les questions « Oui / Non », à choix unique (boutons radio ou liste déroulante), « Chiffres » et « Échelle » le peuvent.'}
      </Hint>
    );
  }

  const choices = current && !sources.some((s) => s.question.id === current.question.id) ? [current, ...sources] : sources;
  const source = current?.question;

  const setOperator = (operator: ConditionOperator) => {
    if (!condition || !source) return;
    // Bascule « est » ↔ « est l'une de » : la sélection est conservée
    let value: DisplayCondition['value'] = defaultValue(source, operator);
    if (source.type === 'CHOICE') {
      if (operator === 'IN') value = Array.isArray(condition.value) ? condition.value : [String(condition.value)].filter(Boolean);
      else value = Array.isArray(condition.value) ? (condition.value[0] ?? '') : condition.value;
    } else if (source.type === 'BOOLEAN' && typeof condition.value === 'boolean') {
      value = condition.value;
    } else if ((source.type === 'NUMBER' || source.type === 'RANGE') && typeof condition.value === 'number') {
      // Changer de comparaison garde le seuil saisi
      value = condition.value;
    }
    onChange({ ...condition, operator, value });
  };

  return (
    <>
      <ToggleField
        label="Afficher cette question uniquement si…"
        checked={condition !== null}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked && firstSource ? newCondition(firstSource.question) : null)}
      />

      {condition && (
        <>
          <Row>
            <ChoiceField
              label="Question"
              value={condition.sourceQuestionId}
              disabled={disabled}
              onChange={(e) => {
                const next = allQuestions.find((s) => s.question.id === e.target.value);
                if (next) onChange(newCondition(next.question));
              }}
            >
              {!current && <option value={condition.sourceQuestionId}>Question supprimée</option>}
              {choices.map(({ question, number: n }) => (
                <option key={question.id} value={question.id}>
                  Q{n}. {plainText(question.label) || 'Question sans intitulé'}
                </option>
              ))}
            </ChoiceField>

            {source && (
              <ChoiceField label="Condition" value={condition.operator} disabled={disabled} onChange={(e) => setOperator(e.target.value as ConditionOperator)}>
                {operatorsFor(source).map((operator) => (
                  <option key={operator} value={operator}>
                    {operatorLabel(operator, source.type)}
                  </option>
                ))}
              </ChoiceField>
            )}
          </Row>

          {source && <ValueInput condition={condition} source={source} disabled={disabled} onChange={(value) => onChange({ ...condition, value })} />}

          {issue ? (
            <HelpBox tone="warning">{issue}</HelpBox>
          ) : (
            <Hint>
              {describeCondition(condition, source, current?.number)} : la question s'affiche. Sinon elle est masquée, n'est pas obligatoire et sa réponse n'est pas enregistrée.
            </Hint>
          )}
        </>
      )}
    </>
  );
};

/** Saisie de la valeur selon le type de la source. */
const ValueInput = ({ condition, source, disabled, onChange }: { condition: DisplayCondition; source: BuilderQuestion; disabled: boolean; onChange: (value: DisplayCondition['value']) => void }) => {
  const { value, operator } = condition;

  switch (source.type) {
    case 'CHOICE': {
      const options = (source.config as ChoiceConfig).options;
      if (operator === 'IN') {
        const selected = Array.isArray(value) ? value : [];
        return (
          <fieldset disabled={disabled}>
            <SubLegend>Réponses</SubLegend>
            <Options>
              {options.map((option, index) => (
                <CheckField
                  key={option.id}
                  label={optionLabel(option.label, index)}
                  checked={selected.includes(option.id)}
                  onChange={(e) => onChange(e.target.checked ? [...selected, option.id] : selected.filter((id) => id !== option.id))}
                />
              ))}
            </Options>
          </fieldset>
        );
      }
      return (
        <ChoiceField label="Réponse" value={String(value)} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
          {options.map((option, index) => (
            <option key={option.id} value={option.id}>
              {optionLabel(option.label, index)}
            </option>
          ))}
        </ChoiceField>
      );
    }

    case 'BOOLEAN': {
      const config = source.config as BooleanConfig;
      return (
        <ChoiceField label="Réponse" value={value === true ? 'true' : 'false'} disabled={disabled} onChange={(e) => onChange(e.target.value === 'true')}>
          <option value="true">{config.trueLabel}</option>
          <option value="false">{config.falseLabel}</option>
        </ChoiceField>
      );
    }

    // Échelle : le seuil se choisit parmi les crans (ex. 0 à 10, ou 2, 4, 6, 22…)
    case 'RANGE': {
      const config = source.config as RangeConfig;
      const scale = rangeScale(config);
      if (scale.length > 0) {
        return (
          <ChoiceField label="Valeur" value={String(value)} disabled={disabled} onChange={(e) => onChange(Number(e.target.value))}>
            {scale.map((v) => (
              <option key={v} value={v}>
                {formatRangeValue(v, config.unit)}
              </option>
            ))}
          </ChoiceField>
        );
      }
      return <ThresholdField value={value} disabled={disabled} onChange={onChange} />;
    }

    case 'NUMBER':
      return <ThresholdField value={value} disabled={disabled} onChange={onChange} unit={(source.config as NumberConfig).unit} />;

    // Source qui n'est plus autorisée (type ou choix multiple modifié) : le problème est signalé au-dessus
    default:
      return null;
  }
};

/** Seuil numérique saisi : la saisie en cours est conservée, seul un nombre valide est transmis. */
const ThresholdField = ({ value, disabled, onChange, unit }: { value: DisplayCondition['value']; disabled: boolean; onChange: (value: number) => void; unit?: string }) => {
  const [text, setText] = useState(typeof value === 'number' ? String(value) : '');
  const parsed = text.trim() === '' ? Number.NaN : Number(text.trim().replace(',', '.'));

  return (
    <TextField
      label={unit ? `Valeur (${unit})` : 'Valeur'}
      inputMode="decimal"
      value={text}
      disabled={disabled}
      error={text.trim() !== '' && !Number.isFinite(parsed) ? 'Nombre attendu.' : undefined}
      onChange={(e) => {
        setText(e.target.value);
        const next = Number(e.target.value.trim().replace(',', '.'));
        if (e.target.value.trim() !== '' && Number.isFinite(next)) onChange(next);
      }}
    />
  );
};

const Row = styled.div`
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: 0 ${({ theme }) => theme.spacing[3]};

  @media (max-width: 600px) {
    grid-template-columns: 1fr;
  }
`;

const Options = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: ${({ theme }) => theme.spacing[1]} ${({ theme }) => theme.spacing[3]};
`;

const SubLegend = styled.legend`
  margin-bottom: ${({ theme }) => theme.spacing[1]};
  font-size: 0.875rem !important;
  font-weight: 600;
  letter-spacing: normal !important;
  text-transform: none !important;
  color: ${({ theme }) => theme.colors.text.heading} !important;
`;

const Hint = styled.p`
  margin: 0;
  font-size: 0.8rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;
