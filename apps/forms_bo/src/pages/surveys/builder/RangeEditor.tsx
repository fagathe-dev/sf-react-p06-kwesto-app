import { formatRangeValue, type RangeConfig } from '@/types';
import { HelpBox } from '@/ui/HelpBox';
import { RangeSlider } from '@/ui/RangeSlider';
import { ChoiceField, SegmentedControl, TextField, ToggleField } from 'core-react/ui';
import { useState } from 'react';
import { styled } from 'styled-components';

interface RangeEditorProps {
  config: RangeConfig;
  onChange: (config: RangeConfig) => void;
  disabled: boolean;
}

type ScaleMode = 'regular' | 'custom';

const MAX_STEPS = 1000;

const parseNumber = (raw: string): number | undefined => {
  if (raw.trim() === '') return undefined;
  const parsed = Number(raw.trim().replace(',', '.'));
  return Number.isFinite(parsed) ? parsed : undefined;
};

/** « 2; 4 ; 6, 22 40 » → [2, 4, 6, 22, 40] (trié, sans doublon). La virgule décimale est acceptée (« 2,5 » avec « ; »). */
const parseValueList = (raw: string): number[] => {
  const separator = raw.includes(';') ? /[;\s]+/ : /[,\s]+/;
  const values = raw
    .split(separator)
    .map(parseNumber)
    .filter((v): v is number => v !== undefined)
    .map((v) => Math.round(v * 1e6) / 1e6);
  return [...new Set(values)].sort((a, b) => a - b);
};

const isOnStep = (value: number, min: number, step: number) => {
  const ratio = (value - min) / step;
  return Math.abs(ratio - Math.round(ratio)) < 1e-9;
};

/** Valeurs d'un intervalle régulier (pour passer en mode personnalisé sans tout ressaisir). */
const regularValues = ({ min, max, step }: RangeConfig): number[] => {
  if (!(step > 0) || !(min < max) || (max - min) / step > 30) return [min, max];
  const count = Math.round((max - min) / step);
  return Array.from({ length: count + 1 }, (_, i) => Math.round((min + i * step) * 1e6) / 1e6);
};

/** Contrôles locaux de l'échelle (mêmes règles que RangeConfig côté API). */
const rangeIssues = (config: RangeConfig): string[] => {
  const issues: string[] = [];
  const { min, max, step, defaultValue, values } = config;

  if (values) {
    if (values.length < 2) issues.push('Une échelle personnalisée doit compter au moins 2 valeurs.');
    if (defaultValue !== null && !values.some((v) => Math.abs(v - defaultValue) < 1e-9)) issues.push('La valeur de départ doit être une valeur de l\'échelle.');
    return issues;
  }

  if (!(min < max)) issues.push('La valeur minimale doit être inférieure à la valeur maximale.');
  else if (!(step > 0)) issues.push('Le pas doit être supérieur à 0.');
  else if ((max - min) / step > MAX_STEPS) issues.push(`L'échelle compte trop de crans (${MAX_STEPS} au maximum) : augmentez le pas.`);
  else if (!isOnStep(max, min, step)) issues.push('L\'écart entre le minimum et le maximum doit être un multiple du pas.');

  if (defaultValue !== null && (defaultValue < min || defaultValue > max || !(step > 0) || !isOnStep(defaultValue, min, step))) {
    issues.push('La valeur de départ doit être une valeur de l\'échelle.');
  }
  return issues;
};

/**
 * Champ numérique qui garde la saisie en cours (« », « - », « 2, ») et ne transmet qu'un nombre valide :
 * on peut vider le champ ou taper un signe sans que la valeur saute.
 */
const NumberField = ({ label, value, onCommit, disabled, hint }: { label: string; value: number; onCommit: (value: number) => void; disabled: boolean; hint?: string }) => {
  const [text, setText] = useState(String(value));
  const [seen, setSeen] = useState(value);

  // Valeur modifiée de l'extérieur (changement de mode…) : le champ suit
  if (value !== seen) {
    setSeen(value);
    if (parseNumber(text) !== value) setText(String(value));
  }

  return (
    <TextField
      label={label}
      inputMode="decimal"
      value={text}
      disabled={disabled}
      hint={hint}
      error={text.trim() !== '' && parseNumber(text) === undefined ? 'Nombre attendu.' : undefined}
      onChange={(e) => {
        setText(e.target.value);
        const parsed = parseNumber(e.target.value);
        if (parsed !== undefined) onCommit(parsed);
      }}
    />
  );
};

/** Liste de valeurs personnalisées, saisie libre ; la liste comprise est rappelée dessous. */
const ValuesField = ({ values, onCommit, disabled, unit }: { values: number[]; onCommit: (values: number[]) => void; disabled: boolean; unit?: string }) => {
  const [text, setText] = useState(values.join('; '));
  const parsed = parseValueList(text);

  return (
    <TextField
      label="Valeurs de l'échelle"
      placeholder="2; 4; 6; 22; 40; 79"
      value={text}
      disabled={disabled}
      hint={
        parsed.length > 0
          ? `${parsed.length} valeur${parsed.length > 1 ? 's' : ''}, dans l'ordre : ${parsed.map((v) => formatRangeValue(v, unit)).join(' · ')}`
          : 'Séparez les valeurs par « ; », une virgule ou un espace.'
      }
      onChange={(e) => {
        setText(e.target.value);
        onCommit(parseValueList(e.target.value));
      }}
    />
  );
};

/**
 * Question RANGE (specs_fonc §4.7) : échelle en intervalle régulier (min, max, pas) ou en valeurs personnalisées
 * (ex. 2, 4, 6, 22, 40, 79), suffixe d'affichage, libellés des extrémités, valeur de départ facultative ;
 * aperçu manipulable du curseur vu par le participant.
 */
export const RangeEditor = ({ config, onChange, disabled }: RangeEditorProps) => {
  const mode: ScaleMode = config.values ? 'custom' : 'regular';
  const issues = rangeIssues(config);
  const scale = config.values ?? regularValues(config);

  const changeMode = (next: ScaleMode) => {
    if (disabled || next === mode) return;
    if (next === 'custom') {
      onChange({ ...config, values: regularValues(config) });
    } else {
      const { values = [], ...regular } = config;
      onChange({ ...regular, min: values[0] ?? 0, max: values[values.length - 1] ?? 10, step: 1, defaultValue: null });
    }
  };

  return (
    <>
      <SegmentedControl<ScaleMode>
        aria-label="Type d'échelle"
        size="sm"
        value={mode}
        onChange={changeMode}
        options={[
          { value: 'regular', label: 'Intervalle régulier' },
          { value: 'custom', label: 'Valeurs personnalisées' },
        ]}
      />

      {mode === 'regular' ? (
        <Grid>
          <NumberField label="Minimum" value={config.min} disabled={disabled} onCommit={(min) => onChange({ ...config, min })} />
          <NumberField label="Maximum" value={config.max} disabled={disabled} onCommit={(max) => onChange({ ...config, max })} />
          <NumberField label="Pas" value={config.step} disabled={disabled} onCommit={(step) => onChange({ ...config, step })} />
        </Grid>
      ) : (
        <ValuesField
          values={config.values ?? []}
          unit={config.unit}
          disabled={disabled}
          onCommit={(values) => onChange({ ...config, values, min: values[0] ?? config.min, max: values[values.length - 1] ?? config.max })}
        />
      )}

      <Grid>
        <TextField label="Suffixe" placeholder="/10, %, €…" maxLength={12} value={config.unit ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, unit: e.target.value || undefined })} />
        <TextField label="Libellé à gauche (minimum)" placeholder="Pas du tout" maxLength={60} value={config.minLabel ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, minLabel: e.target.value || undefined })} />
        <TextField label="Libellé à droite (maximum)" placeholder="Absolument" maxLength={60} value={config.maxLabel ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, maxLabel: e.target.value || undefined })} />
      </Grid>

      <ToggleField
        label="Valeur de départ"
        checked={config.defaultValue !== null}
        disabled={disabled}
        onChange={(e) => onChange({ ...config, defaultValue: e.target.checked ? (scale[0] ?? config.min) : null })}
      />
      {config.defaultValue !== null &&
        (mode === 'custom' ? (
          <ChoiceField label="Position de départ" value={String(config.defaultValue)} disabled={disabled} onChange={(e) => onChange({ ...config, defaultValue: Number(e.target.value) })}>
            {scale.map((v) => (
              <option key={v} value={v}>
                {formatRangeValue(v, config.unit)}
              </option>
            ))}
          </ChoiceField>
        ) : (
          <NumberField
            label="Position de départ"
            value={config.defaultValue}
            disabled={disabled}
            hint="Comptée comme réponse : le participant ne déplace le curseur que s'il le souhaite."
            onCommit={(defaultValue) => onChange({ ...config, defaultValue })}
          />
        ))}
      <Hint>
        {config.defaultValue === null
          ? 'Sans valeur de départ, le curseur est au milieu et grisé : le participant doit le déplacer pour répondre.'
          : `Le curseur démarre sur « ${formatRangeValue(config.defaultValue, config.unit)} ».`}
      </Hint>

      {issues.map((issue) => (
        <HelpBox key={issue} tone="warning">
          {issue}
        </HelpBox>
      ))}

      {issues.length === 0 && (
        <PreviewBox>
          <span>Aperçu participant</span>
          <RangePreview key={JSON.stringify([config.min, config.max, config.step, config.values, config.defaultValue])} config={config} />
        </PreviewBox>
      )}
    </>
  );
};

/** Aperçu manipulable, réinitialisé quand l'échelle ou la valeur de départ changent. */
const RangePreview = ({ config }: { config: RangeConfig }) => {
  const [value, setValue] = useState<number | null>(config.defaultValue);
  return <RangeSlider aria-label="Aperçu du curseur" config={config} value={value} onChange={setValue} />;
};

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0 ${({ theme }) => theme.spacing[3]};
`;

const Hint = styled.p`
  margin: 0;
  font-size: 0.8rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const PreviewBox = styled.div`
  padding: ${({ theme }) => theme.spacing[3]};
  border: 1px dashed ${({ theme }) => theme.colors.border.strong};
  border-radius: ${({ theme }) => theme.radii.md};

  & > span {
    display: block;
    margin-bottom: ${({ theme }) => theme.spacing[2]};
    font-size: 0.75rem;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;
