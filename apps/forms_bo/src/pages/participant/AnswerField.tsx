import { errorMessage } from '@/services/errors';
import { type AnswerValue, type Asset, type BooleanConfig, type ChoiceConfig, type DateConfig, type FileConfig, type NumberConfig, type PlatformConfig, type Question, type RangeConfig, type TextConfig } from '@/types';
import { BooleanControl } from '@/ui/BooleanControl';
import { HelpBox } from '@/ui/HelpBox';
import { InlineMarkdown, MarkdownBlock } from '@/ui/Markdown';
import { formatBytes } from '@/utils/format';
import { Icon } from 'core-react/icons';
import { FileDropzone, IconButton } from 'core-react/ui';
import { RangeSlider } from '@/ui/RangeSlider';
import { useState } from 'react';
import { styled } from 'styled-components';
import { ChoiceAutocomplete, ChoiceSearch } from './ChoiceAutocomplete';
import { CHOICE_SEARCH_THRESHOLD, matches } from './choiceSearch';
import { effectiveHelpText } from './helpText';

/** Dépôt des fichiers : API réelle pour le participant, simulation locale pour l'aperçu du builder. */
export interface FileTransport {
  upload: (questionId: string, file: File, onProgress: (percent: number) => void) => Promise<Asset>;
  remove: (assetId: string) => Promise<void>;
}

interface AnswerFieldProps {
  question: Question;
  number: number;
  value: AnswerValue | undefined;
  error?: string;
  files: FileTransport;
  platform: PlatformConfig;
  assets: Asset[];
  onChange: (value: AnswerValue) => void;
  onAssetsChange: (assets: Asset[]) => void;
}

/**
 * Une question du formulaire participant : intitulé, encart d'aide contextuelle (lu après l'intitulé
 * et relié au champ par aria-describedby), champ adapté au type, message d'erreur.
 */
export const AnswerField = ({ question, number, value, error, files, platform, assets, onChange, onAssetsChange }: AnswerFieldProps) => {
  const fieldId = `q-${question.id}`;
  const helpText = effectiveHelpText(question);
  const helpId = helpText ? `${fieldId}-help` : undefined;
  const errorId = error ? `${fieldId}-error` : undefined;
  const describedBy = [helpId, errorId].filter(Boolean).join(' ') || undefined;
  const isGroup =
    (question.type === 'CHOICE' && (question.config as ChoiceConfig).widget !== 'select') || (question.type === 'BOOLEAN' && (question.config as BooleanConfig).widget !== 'switch');
  const label = (
    <>
      <Number>{number}.</Number> <InlineMarkdown source={question.label} />
      {question.required ? <Required aria-label="obligatoire"> *</Required> : <Optional> (facultatif)</Optional>}
    </>
  );

  const common = { id: fieldId, describedBy, invalid: Boolean(error), required: question.required };

  return (
    <Field $invalid={Boolean(error)}>
      {isGroup ? <Legend id={`${fieldId}-label`}>{label}</Legend> : <Label htmlFor={fieldId}>{label}</Label>}
      {helpText && (
        <HelpBox id={helpId} title={question.helpText ? question.helpTitle : null}>
          <MarkdownBlock source={helpText} />
        </HelpBox>
      )}

      {question.type === 'TEXT' && <TextInput {...common} config={question.config as TextConfig} value={String(value ?? '')} onChange={onChange} />}
      {question.type === 'NUMBER' && <NumberInput {...common} config={question.config as NumberConfig} value={String(value ?? '')} onChange={onChange} />}
      {question.type === 'CHOICE' && <ChoiceInput {...common} config={question.config as ChoiceConfig} value={value} onChange={onChange} labelId={`${fieldId}-label`} />}
      {question.type === 'DATE' && <DateInput {...common} config={question.config as DateConfig} value={String(value ?? '')} onChange={onChange} />}
      {question.type === 'RANGE' && (
        <RangeInput
          id={fieldId}
          config={question.config as RangeConfig}
          value={value === undefined || value === '' ? null : parseFloat(String(value))}
          describedBy={describedBy}
          invalid={Boolean(error)}
          onChange={(next) => onChange(String(next))}
        />
      )}
      {question.type === 'BOOLEAN' && (
        <BooleanControl
          id={fieldId}
          labelId={`${fieldId}-label`}
          config={question.config as BooleanConfig}
          value={value === 'true' ? true : value === 'false' ? false : null}
          describedBy={describedBy}
          invalid={Boolean(error)}
          required={question.required}
          onChange={(next) => onChange(String(next))}
        />
      )}
      {question.type === 'FILE' && (
        <FileInput
          {...common}
          files={files}
          questionId={question.id}
          config={question.config as FileConfig}
          platform={platform}
          assets={assets}
          onAssetsChange={(next) => {
            onAssetsChange(next);
            onChange(next.map((a) => a.id));
          }}
        />
      )}

      {error && (
        <ErrorText id={errorId} role="alert">
          <Icon name="alert-circle-outline" size={16} /> {error}
        </ErrorText>
      )}
    </Field>
  );
};

interface InputProps {
  id: string;
  describedBy?: string;
  invalid: boolean;
  required: boolean;
}

const TextInput = ({ id, describedBy, invalid, required, config, value, onChange }: InputProps & { config: TextConfig; value: string; onChange: (v: string) => void }) => {
  const max = config.maxLength ?? 5000;
  const props = {
    id,
    value,
    maxLength: max,
    placeholder: config.placeholder,
    'aria-describedby': describedBy,
    'aria-invalid': invalid || undefined,
    'aria-required': required,
  };

  return (
    <>
      {config.multiline ? <TextArea {...props} rows={5} onChange={(e) => onChange(e.target.value)} /> : <Input {...props} type="text" onChange={(e) => onChange(e.target.value)} />}
      <Hint>
        {value.length} / {max} caractères{config.minLength ? ` · ${config.minLength} minimum` : ''}
      </Hint>
    </>
  );
};

/** Clavier numérique sur mobile ; « . » et « , » acceptés comme séparateur décimal. */
const NumberInput = ({ id, describedBy, invalid, required, config, value, onChange }: InputProps & { config: NumberConfig; value: string; onChange: (v: string) => void }) => {
  const bounds = [config.min !== undefined ? `min. ${config.min.toLocaleString('fr-FR')}` : null, config.max !== undefined ? `max. ${config.max.toLocaleString('fr-FR')}` : null].filter(Boolean);

  return (
    <>
      <InputGroup>
        <Input
          id={id}
          type="text"
          inputMode={config.allowDecimal ? 'decimal' : 'numeric'}
          autoComplete="off"
          value={value}
          aria-describedby={describedBy}
          aria-invalid={invalid || undefined}
          aria-required={required}
          onChange={(e) => onChange(e.target.value)}
        />
        {config.unit && <Unit>{config.unit}</Unit>}
      </InputGroup>
      {(bounds.length > 0 || !config.allowDecimal) && (
        <Hint>
          {[config.allowDecimal ? null : 'Nombre entier', ...bounds].filter(Boolean).join(' · ')}
        </Hint>
      )}
    </>
  );
};

const ChoiceInput = ({ id, describedBy, invalid, required, config, value, onChange, labelId }: InputProps & { config: ChoiceConfig; value: AnswerValue | undefined; onChange: (v: AnswerValue) => void; labelId: string }) => {
  const selected = Array.isArray(value) ? value : value ? [value] : [];
  const [query, setQuery] = useState('');
  // Au moins 10 options : recherche pour retrouver rapidement une réponse
  const searchable = config.options.length >= CHOICE_SEARCH_THRESHOLD;

  if (config.widget === 'select' && searchable) {
    return (
      <ChoiceAutocomplete
        id={id}
        options={config.options}
        value={selected[0] ?? null}
        describedBy={describedBy}
        invalid={invalid}
        required={required}
        onChange={(optionId) => onChange(optionId ? [optionId] : [])}
      />
    );
  }

  if (config.widget === 'select') {
    return (
      <Select id={id} value={selected[0] ?? ''} aria-describedby={describedBy} aria-invalid={invalid || undefined} aria-required={required} onChange={(e) => onChange(e.target.value ? [e.target.value] : [])}>
        <option value="">— Sélectionnez une réponse —</option>
        {config.options.map((option) => (
          <option key={option.id} value={option.id}>
            {option.label}
          </option>
        ))}
      </Select>
    );
  }

  const multiple = config.widget === 'checkbox';
  // Les réponses déjà cochées restent visibles quel que soit le filtre
  const visibleOptions = searchable && query.trim() !== '' ? config.options.filter((o) => selected.includes(o.id) || matches(o.label, query)) : config.options;

  return (
    <Choices role={multiple ? 'group' : 'radiogroup'} aria-labelledby={labelId} aria-describedby={describedBy} aria-invalid={invalid || undefined} aria-required={required}>
      {searchable && <ChoiceSearch id={`${id}-search`} total={config.options.length} shown={visibleOptions.length} query={query} onQuery={setQuery} />}
      {visibleOptions.length === 0 && <Hint>Aucune réponse ne correspond à « {query} ».</Hint>}
      {visibleOptions.map((option) => {
        const checked = selected.includes(option.id);
        return (
          <ChoiceOption key={option.id} $checked={checked}>
            <input
              type={multiple ? 'checkbox' : 'radio'}
              name={id}
              value={option.id}
              checked={checked}
              onChange={() => onChange(multiple ? (checked ? selected.filter((s) => s !== option.id) : [...selected, option.id]) : [option.id])}
            />
            <span>{option.label}</span>
          </ChoiceOption>
        );
      })}
    </Choices>
  );
};

const DateInput = ({ id, describedBy, invalid, required, config, value, onChange }: InputProps & { config: DateConfig; value: string; onChange: (v: string) => void }) => (
  <Input
    id={id}
    type="date"
    value={value}
    min={config.minDate}
    max={config.maxDate}
    aria-describedby={describedBy}
    aria-invalid={invalid || undefined}
    aria-required={required}
    onChange={(e) => onChange(e.target.value)}
    style={{ maxWidth: 240 }}
  />
);

/**
 * Échelle : curseur (valeur en grand, « 8/10 », libellés d'extrémités), intervalle régulier ou valeurs personnalisées.
 * Sans valeur de départ, le curseur reste au milieu, grisé, tant que le participant ne l'a pas déplacé.
 */
const RangeInput = ({ id, config, value, describedBy, invalid, onChange }: { id: string; config: RangeConfig; value: number | null; describedBy?: string; invalid: boolean; onChange: (value: number) => void }) => (
  <RangeSlider id={id} config={config} value={value} describedBy={describedBy} invalid={invalid} onChange={onChange} />
);

interface Upload {
  key: string;
  name: string;
  progress: number;
}

/** Dépôt par glisser-déposer ou explorateur, avec progression ; la taille effective est affichée. */
const FileInput = ({ id, describedBy, files: transport, questionId, config, platform, assets, onAssetsChange }: InputProps & { files: FileTransport; questionId: string; config: FileConfig; platform: PlatformConfig; assets: Asset[]; onAssetsChange: (assets: Asset[]) => void }) => {
  const [uploads, setUploads] = useState<Upload[]>([]);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const effectiveMb = Math.min(config.maxSizeMb ?? platform.uploadMaxSizeMb, platform.uploadMaxSizeMb);
  const formats = platform.fileFormats.filter((f) => config.allowedMimeTypes.includes(f.mimeType));
  const remaining = config.maxFiles - assets.length - uploads.length;

  const upload = async (files: File[]) => {
    setUploadError(null);
    let current = assets;

    for (const file of files.slice(0, Math.max(0, remaining))) {
      const key = `${file.name}-${file.size}-${Date.now()}`;
      setUploads((list) => [...list, { key, name: file.name, progress: 0 }]);
      try {
        const asset = await transport.upload(questionId, file, (progress) => setUploads((list) => list.map((u) => (u.key === key ? { ...u, progress } : u))));
        current = [...current, asset];
        onAssetsChange(current);
      } catch (error) {
        setUploadError(`${file.name} : ${errorMessage(error, 'le fichier n\'a pas pu être envoyé.')}`);
      } finally {
        setUploads((list) => list.filter((u) => u.key !== key));
      }
    }

    if (files.length > remaining) setUploadError(`Vous pouvez déposer au maximum ${config.maxFiles} fichier(s).`);
  };

  const remove = async (asset: Asset) => {
    try {
      await transport.remove(asset.id);
      onAssetsChange(assets.filter((a) => a.id !== asset.id));
    } catch (error) {
      setUploadError(errorMessage(error));
    }
  };

  return (
    <div id={id} aria-describedby={describedBy}>
      {remaining > 0 && (
        <FileDropzone
          accept={config.allowedMimeTypes.join(',')}
          multiple={config.maxFiles > 1}
          maxSizeMb={effectiveMb}
          hint={`${formats.map((f) => f.extension.toUpperCase()).join(', ')} · ${effectiveMb} Mo maximum par fichier · ${config.maxFiles} fichier(s) au maximum`}
          onFilesSelected={(files) => void upload(files)}
        />
      )}
      {uploads.map((u) => (
        <FileRow key={u.key}>
          <Icon name="cloud-upload-outline" size={18} />
          <span>{u.name}</span>
          <ProgressTrack role="progressbar" aria-valuenow={u.progress} aria-valuemin={0} aria-valuemax={100} aria-label={`Envoi de ${u.name}`}>
            <ProgressFill style={{ width: `${u.progress}%` }} />
          </ProgressTrack>
          <Hint>{u.progress} %</Hint>
        </FileRow>
      ))}
      {assets.map((asset) => (
        <FileRow key={asset.id}>
          <Icon name="file-check-outline" size={18} />
          <span>{asset.originalName}</span>
          <Hint>{formatBytes(asset.size)}</Hint>
          <IconButton name="close" size="sm" variant="ghost" aria-label={`Retirer ${asset.originalName}`} onClick={() => void remove(asset)} />
        </FileRow>
      ))}
      {uploadError && <ErrorText role="alert">{uploadError}</ErrorText>}
    </div>
  );
};

const Field = styled.fieldset<{ $invalid: boolean }>`
  position: relative;
  margin: 0;
  padding: ${({ theme }) => theme.spacing[5]};
  border: 1px solid ${({ theme, $invalid }) => ($invalid ? theme.colors.danger.base : theme.colors.border.default)};
  border-radius: ${({ theme }) => theme.radii.lg};
  background: ${({ theme }) => theme.colors.background.surface};
  min-width: 0;

  & > * + * {
    margin-top: ${({ theme }) => theme.spacing[3]};
  }

  /* La legend flotte (voir Legend) : le contenu suivant passe dessous. */
  & > legend + * {
    clear: both;
    margin-top: 0;
  }
`;

const labelStyles = `
  display: block;
  font-size: 1.05rem;
  font-weight: 600;
  line-height: 1.4;
  width: 100%;
  margin: 0;
  padding: 0;
`;

const Label = styled.label`
  ${labelStyles}
`;

/* Flottante, la legend n'est plus dessinée sur la bordure du fieldset : elle reste dans le padding de la carte. */
const Legend = styled.legend`
  float: left;
  ${labelStyles}
  margin-bottom: ${({ theme }) => theme.spacing[3]};
`;

const Number = styled.span`
  color: ${({ theme }) => theme.colors.primary.base};
`;

const Required = styled.span`
  color: ${({ theme }) => theme.colors.danger.base};
`;

const Optional = styled.span`
  font-weight: 400;
  font-size: 0.85rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const controlStyles = ({ theme }: { theme: import('styled-components').DefaultTheme }) => `
  width: 100%;
  padding: ${theme.spacing[3]};
  border: 1px solid ${theme.colors.border.strong};
  border-radius: ${theme.radii.md};
  background: ${theme.colors.background.body};
  color: ${theme.colors.text.body};
  font: inherit;
  font-size: 1rem;

  &:focus {
    outline: 2px solid ${theme.colors.primary.base};
    outline-offset: 1px;
  }

  &[aria-invalid='true'] {
    border-color: ${theme.colors.danger.base};
  }
`;

const Input = styled.input`
  ${controlStyles}
`;

const TextArea = styled.textarea`
  ${controlStyles}
  resize: vertical;
`;

const Select = styled.select`
  ${controlStyles}
`;

const InputGroup = styled.div`
  display: flex;
  align-items: stretch;
  max-width: 320px;

  input {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
  }
`;

const Unit = styled.span`
  display: inline-flex;
  align-items: center;
  padding: 0 ${({ theme }) => theme.spacing[3]};
  border: 1px solid ${({ theme }) => theme.colors.border.strong};
  border-left: 0;
  border-radius: 0 ${({ theme }) => theme.radii.md} ${({ theme }) => theme.radii.md} 0;
  background: ${({ theme }) => theme.colors.background.surfaceHover};
`;

const Choices = styled.div`
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[2]};
`;

const ChoiceOption = styled.label<{ $checked: boolean }>`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
  padding: ${({ theme }) => theme.spacing[3]};
  border: 1px solid ${({ theme, $checked }) => ($checked ? theme.colors.primary.base : theme.colors.border.default)};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme, $checked }) => ($checked ? theme.colors.primary.subtle : 'transparent')};
  cursor: pointer;

  input {
    width: 1.15rem;
    height: 1.15rem;
    accent-color: ${({ theme }) => theme.colors.primary.base};
  }

  &:focus-within {
    outline: 2px solid ${({ theme }) => theme.colors.primary.base};
    outline-offset: 1px;
  }
`;

const Hint = styled.span`
  display: block;
  font-size: 0.8rem;
  color: ${({ theme }) => theme.colors.text.muted};
`;

const ErrorText = styled.p`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[1]};
  margin: ${({ theme }) => theme.spacing[2]} 0 0;
  font-size: 0.875rem;
  color: ${({ theme }) => theme.colors.danger.base};
`;

const FileRow = styled.div`
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  margin-top: ${({ theme }) => theme.spacing[2]};
  padding: ${({ theme }) => theme.spacing[2]} ${({ theme }) => theme.spacing[3]};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.surfaceHover};

  span {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
`;

const ProgressTrack = styled.div`
  width: 120px;
  height: 6px;
  border-radius: ${({ theme }) => theme.radii.pill};
  background: ${({ theme }) => theme.colors.border.default};
  overflow: hidden;
`;

const ProgressFill = styled.div`
  height: 100%;
  background: ${({ theme }) => theme.colors.primary.base};
  transition: width 0.2s ease;
`;
