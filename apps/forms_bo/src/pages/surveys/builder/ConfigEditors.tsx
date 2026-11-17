import { useConfigStore } from '@/stores/config.store';
import {
  BOOLEAN_PRESETS,
  type RangeConfig,
  type BooleanConfig,
  type BooleanPreset,
  type BooleanWidget,
  type ChoiceConfig,
  type ChoiceWidget,
  type DateConfig,
  type FileConfig,
  type NumberConfig,
  type QuestionConfig,
  type QuestionType,
  type TextConfig,
} from '@/types';
import { CHOICE_SEARCH_THRESHOLD } from '@/pages/participant/choiceSearch';
import { BooleanControl } from '@/ui/BooleanControl';
import { HelpBox } from '@/ui/HelpBox';
import { Icon } from 'core-react/icons';
import { Button, CheckField, ChoiceField, IconButton, SegmentedControl, TextField, ToggleField } from 'core-react/ui';
import { useId, useState } from 'react';
import { styled } from 'styled-components';
import { newOption } from './builderModel';
import { RangeEditor } from './RangeEditor';

interface EditorProps<T> {
  config: T;
  onChange: (config: T) => void;
  disabled: boolean;
}

const numberOrUndefined = (value: string): number | undefined => {
  if (value.trim() === '') return undefined;
  const parsed = Number(value.replace(',', '.'));
  return Number.isFinite(parsed) ? parsed : undefined;
};

/** Paramètres propres à chaque type de question (specs_fonc §4). */
export const ConfigEditor = ({ type, config, onChange, disabled }: { type: QuestionType } & EditorProps<QuestionConfig>) => {
  switch (type) {
    case 'TEXT':
      return <TextEditor config={config as TextConfig} onChange={onChange} disabled={disabled} />;
    case 'NUMBER':
      return <NumberEditor config={config as NumberConfig} onChange={onChange} disabled={disabled} />;
    case 'CHOICE':
      return <ChoiceEditor config={config as ChoiceConfig} onChange={onChange} disabled={disabled} />;
    case 'DATE':
      return <DateEditor config={config as DateConfig} onChange={onChange} disabled={disabled} />;
    case 'FILE':
      return <FileEditor config={config as FileConfig} onChange={onChange} disabled={disabled} />;
    case 'BOOLEAN':
      return <BooleanEditor config={config as BooleanConfig} onChange={onChange} disabled={disabled} />;
    case 'RANGE':
      return <RangeEditor config={config as RangeConfig} onChange={onChange} disabled={disabled} />;
  }
};

const PRESETS: Array<{ value: BooleanPreset; label: string }> = [
  { value: 'YES_NO', label: 'Oui / Non' },
  { value: 'TRUE_FALSE', label: 'Vrai / Faux' },
  { value: 'CUSTOM', label: 'Personnalisé' },
];

const BOOLEAN_WIDGETS: Array<{ value: BooleanWidget; label: string }> = [
  { value: 'buttons', label: 'Deux boutons' },
  { value: 'switch', label: 'Interrupteur' },
];

type DefaultChoice = 'none' | 'true' | 'false';

/** Preset correspondant aux libellés saisis : toute modification qui s'écarte d'un modèle passe en « Personnalisé ». */
const presetOf = (trueLabel: string, falseLabel: string): BooleanPreset =>
  (Object.entries(BOOLEAN_PRESETS).find(([, [t, f]]) => t === trueLabel && f === falseLabel)?.[0] as BooleanPreset | undefined) ?? 'CUSTOM';

/**
 * Question BOOLEAN (specs_fonc §4.6) : libellés (modèles ou personnalisés), rendu en deux boutons ou en
 * interrupteur, valeur par défaut. Un interrupteur a toujours une valeur par défaut (« Non » si rien n'est choisi).
 */
const BooleanEditor = ({ config, onChange, disabled }: EditorProps<BooleanConfig>) => {
  const setLabels = (trueLabel: string, falseLabel: string) => onChange({ ...config, trueLabel, falseLabel, preset: presetOf(trueLabel, falseLabel) });

  const choosePreset = (preset: BooleanPreset) => {
    if (preset === 'CUSTOM') onChange({ ...config, preset });
    else setLabels(...BOOLEAN_PRESETS[preset]);
  };

  const chooseWidget = (widget: BooleanWidget) => onChange({ ...config, widget, defaultValue: widget === 'switch' ? (config.defaultValue ?? false) : config.defaultValue });

  const defaultChoice: DefaultChoice = config.defaultValue === null ? 'none' : config.defaultValue ? 'true' : 'false';
  const defaultOptions: Array<{ value: DefaultChoice; label: string }> = [
    ...(config.widget === 'buttons' ? [{ value: 'none' as const, label: 'Aucune' }] : []),
    { value: 'true', label: config.trueLabel || 'Vrai' },
    { value: 'false', label: config.falseLabel || 'Faux' },
  ];

  const sameLabels = config.trueLabel.trim() !== '' && config.trueLabel.trim().toLowerCase() === config.falseLabel.trim().toLowerCase();
  const missingLabel = config.trueLabel.trim() === '' || config.falseLabel.trim() === '';

  return (
    <>
      <SubLabel>Libellés</SubLabel>
      <SegmentedControl aria-label="Modèle de libellés" size="sm" options={PRESETS} value={config.preset} onChange={(preset) => !disabled && choosePreset(preset)} />
      <Grid>
        <TextField label="Réponse « vrai »" maxLength={80} value={config.trueLabel} disabled={disabled} onChange={(e) => setLabels(e.target.value, config.falseLabel)} />
        <TextField label="Réponse « faux »" maxLength={80} value={config.falseLabel} disabled={disabled} onChange={(e) => setLabels(config.trueLabel, e.target.value)} />
      </Grid>
      {missingLabel && <HelpBox tone="warning">Les deux libellés sont obligatoires.</HelpBox>}
      {sameLabels && <HelpBox tone="warning">Les deux libellés doivent être différents.</HelpBox>}

      <SubLabel>Affichage</SubLabel>
      <SegmentedControl aria-label="Affichage de la réponse" size="sm" options={BOOLEAN_WIDGETS} value={config.widget} onChange={(widget) => !disabled && chooseWidget(widget)} />

      <SubLabel>Valeur par défaut</SubLabel>
      <SegmentedControl
        aria-label="Valeur par défaut"
        size="sm"
        options={defaultOptions}
        value={defaultChoice}
        onChange={(choice) => !disabled && onChange({ ...config, defaultValue: choice === 'none' ? null : choice === 'true' })}
      />
      <Hint>
        {config.defaultValue === null
          ? 'Aucune réponse n\'est présélectionnée : le participant doit choisir.'
          : `« ${config.defaultValue ? config.trueLabel : config.falseLabel} » est présélectionné à l'ouverture du formulaire et compte comme réponse ; le participant le change s'il le souhaite.`}
        {config.widget === 'switch' && ' Un interrupteur a toujours une valeur.'}
      </Hint>

      <PreviewBox>
        <span>Aperçu participant</span>
        <BooleanPreview key={`${config.widget}-${String(config.defaultValue)}`} config={config} />
      </PreviewBox>
    </>
  );
};

/** Aperçu manipulable, réinitialisé quand le rendu ou la valeur par défaut changent. */
const BooleanPreview = ({ config }: { config: BooleanConfig }) => {
  const [value, setValue] = useState<boolean | null>(config.defaultValue ?? (config.widget === 'switch' ? false : null));
  const id = useId();
  return <BooleanControl id={`preview-${id}`} config={config} value={value} onChange={setValue} />;
};

const TextEditor = ({ config, onChange, disabled }: EditorProps<TextConfig>) => (
  <>
    <ToggleField
      label="Réponse longue (zone de texte multiligne)"
      checked={config.multiline ?? false}
      disabled={disabled}
      onChange={(e) => onChange({ ...config, multiline: e.target.checked, maxLength: e.target.checked ? 5000 : 255 })}
    />
    <Grid>
      <TextField
        label="Longueur minimale"
        type="number"
        min={0}
        value={config.minLength ?? ''}
        disabled={disabled}
        onChange={(e) => onChange({ ...config, minLength: numberOrUndefined(e.target.value) })}
      />
      <TextField
        label="Longueur maximale"
        type="number"
        min={1}
        max={5000}
        value={config.maxLength ?? ''}
        disabled={disabled}
        onChange={(e) => onChange({ ...config, maxLength: numberOrUndefined(e.target.value) })}
      />
    </Grid>
    <TextField label="Texte indicatif (placeholder)" value={config.placeholder ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, placeholder: e.target.value || undefined })} />
  </>
);

const NumberEditor = ({ config, onChange, disabled }: EditorProps<NumberConfig>) => (
  <>
    <ToggleField
      label="Autoriser les décimales"
      checked={config.allowDecimal ?? false}
      disabled={disabled}
      onChange={(e) => onChange({ ...config, allowDecimal: e.target.checked })}
    />
    <Grid>
      <TextField label="Valeur minimale" inputMode="decimal" value={config.min ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, min: numberOrUndefined(e.target.value) })} />
      <TextField label="Valeur maximale" inputMode="decimal" value={config.max ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, max: numberOrUndefined(e.target.value) })} />
      <TextField label="Pas suggéré" inputMode="decimal" value={config.step ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, step: numberOrUndefined(e.target.value) })} />
      <TextField label="Unité" placeholder="€, kg, %, ans…" maxLength={12} value={config.unit ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, unit: e.target.value || undefined })} />
    </Grid>
  </>
);

const WIDGETS: Array<{ value: ChoiceWidget; label: string; icon: string }> = [
  { value: 'radio', label: 'Choix unique', icon: 'check-circle-outline' },
  { value: 'checkbox', label: 'Choix multiple', icon: 'list-checks' },
  { value: 'select', label: 'Liste déroulante', icon: 'chevron-down' },
];

const ChoiceEditor = ({ config, onChange, disabled }: EditorProps<ChoiceConfig>) => {
  const updateOption = (id: string, label: string) =>
    onChange({ ...config, options: config.options.map((o) => (o.id === id ? { ...o, label } : o)) });
  const moveOption = (index: number, delta: number) => {
    const options = [...config.options];
    const [item] = options.splice(index, 1);
    if (item) options.splice(index + delta, 0, item);
    onChange({ ...config, options });
  };

  return (
    <>
      <SegmentedControl aria-label="Affichage des choix" size="sm" options={WIDGETS} value={config.widget} onChange={(widget) => !disabled && onChange({ ...config, widget })} />
      <Options aria-label="Options de réponse">
        {config.options.map((option, index) => (
          <li key={option.id}>
            <TextField
              aria-label={`Option ${index + 1}`}
              placeholder={`Option ${index + 1}`}
              value={option.label}
              maxLength={200}
              disabled={disabled}
              onChange={(e) => updateOption(option.id, e.target.value)}
            />
            {!disabled && (
              <>
                <IconButton name="arrow-up" size="sm" variant="ghost" aria-label="Monter l'option" disabled={index === 0} onClick={() => moveOption(index, -1)} />
                <IconButton name="arrow-down" size="sm" variant="ghost" aria-label="Descendre l'option" disabled={index === config.options.length - 1} onClick={() => moveOption(index, 1)} />
                <IconButton name="close" size="sm" variant="ghost" aria-label="Supprimer l'option" onClick={() => onChange({ ...config, options: config.options.filter((o) => o.id !== option.id) })} />
              </>
            )}
          </li>
        ))}
      </Options>
      {config.options.length < 2 && <HelpBox tone="warning">Une question à choix doit proposer au moins 2 options.</HelpBox>}
      <Hint>
        {config.options.length >= CHOICE_SEARCH_THRESHOLD
          ? `${config.options.length} options : le participant dispose d'un champ de recherche pour filtrer les réponses${config.widget === 'select' ? ' (liste à saisie semi-automatique)' : ''}.`
          : `À partir de ${CHOICE_SEARCH_THRESHOLD} options, le participant dispose d'un champ de recherche pour filtrer les réponses.`}
      </Hint>
      {!disabled && (
        <div>
          <Button type="button" $variant="ghost" $size="sm" onClick={() => onChange({ ...config, options: [...config.options, newOption()] })}>
            <Icon name="plus" size={16} /> Ajouter une option
          </Button>
        </div>
      )}
    </>
  );
};

const DateEditor = ({ config, onChange, disabled }: EditorProps<DateConfig>) => (
  <Grid>
    <TextField label="Date minimale" type="date" value={config.minDate ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, minDate: e.target.value || undefined })} />
    <TextField label="Date maximale" type="date" value={config.maxDate ?? ''} disabled={disabled} onChange={(e) => onChange({ ...config, maxDate: e.target.value || undefined })} />
  </Grid>
);

/**
 * Question FILE : la box d'information rappelle la limite de la plateforme, lue depuis le serveur
 * (jamais codée en dur), et passe en avertissement si la valeur saisie la dépasse (specs_fonc §4.5).
 */
const FileEditor = ({ config, onChange, disabled }: EditorProps<FileConfig>) => {
  const platform = useConfigStore((state) => state.config);
  const limit = platform?.uploadMaxSizeMb;
  const exceeds = limit !== undefined && config.maxSizeMb !== undefined && config.maxSizeMb > limit;

  const toggleMime = (mimeType: string, checked: boolean) =>
    onChange({
      ...config,
      allowedMimeTypes: checked ? [...config.allowedMimeTypes, mimeType] : config.allowedMimeTypes.filter((m) => m !== mimeType),
    });

  return (
    <>
      {limit !== undefined &&
        (exceeds ? (
          <HelpBox tone="warning" title="Limite dépassée">
            La taille saisie ({config.maxSizeMb} Mo) dépasse la limite de la plateforme. Une limite de <strong>{limit} Mo par fichier</strong> sera appliquée à cette question.
          </HelpBox>
        ) : (
          <HelpBox title="Envoi de fichiers">
            Les participants pourront déposer des fichiers. La taille maximale acceptée par kwesto est de <strong>{limit} Mo par fichier</strong>. Vous pouvez fixer une limite plus basse pour cette question.
          </HelpBox>
        ))}
      <Grid>
        <TextField
          label="Poids maximal par fichier (Mo)"
          type="number"
          min={1}
          value={config.maxSizeMb ?? ''}
          placeholder={limit ? String(limit) : ''}
          disabled={disabled}
          onChange={(e) => onChange({ ...config, maxSizeMb: numberOrUndefined(e.target.value) })}
        />
        <ChoiceField label="Nombre de fichiers" value={config.maxFiles} disabled={disabled} onChange={(e) => onChange({ ...config, maxFiles: Number(e.target.value) })}>
          {[1, 2, 3, 4, 5, 10].map((n) => (
            <option key={n} value={n}>
              {n === 1 ? '1 fichier' : `Jusqu'à ${n} fichiers`}
            </option>
          ))}
        </ChoiceField>
      </Grid>
      <fieldset disabled={disabled}>
        <legend>Formats acceptés</legend>
        <Formats>
          {(platform?.fileFormats ?? []).map((format) => (
            <CheckField
              key={format.mimeType}
              label={format.label}
              checked={config.allowedMimeTypes.includes(format.mimeType)}
              onChange={(e) => toggleMime(format.mimeType, e.target.checked)}
            />
          ))}
        </Formats>
      </fieldset>
      {config.allowedMimeTypes.length === 0 && <HelpBox tone="warning">Autorisez au moins un format de fichier.</HelpBox>}
    </>
  );
};

const Grid = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0 ${({ theme }) => theme.spacing[3]};
`;

const Options = styled.ol`
  list-style: none;
  margin: 0;
  padding: 0;

  li {
    display: flex;
    align-items: flex-start;
    gap: ${({ theme }) => theme.spacing[1]};

    & > :first-child {
      flex: 1;
    }
  }
`;

const SubLabel = styled.span`
  display: block;
  font-size: 0.875rem;
  font-weight: 600;
  color: ${({ theme }) => theme.colors.text.heading};
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

const Formats = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: ${({ theme }) => theme.spacing[1]} ${({ theme }) => theme.spacing[3]};
`;
