import { useAsync } from '@/hooks/useAsync';
import { assetPath, downloadAsset, fetchQuestionFiles, fetchVerbatims } from '@/services/api';
import { fetchObjectUrl } from '@/services/http';
import {
  formatRangeValue,
  type RangeStats,
  QUESTION_TYPE_ICONS,
  QUESTION_TYPE_LABELS,
  type Asset,
  type BooleanStats,
  type ChoiceStats,
  type DateStats,
  type FileStats,
  type NumberConfig,
  type NumberStats,
  type QuestionStats,
  type TextStats,
} from '@/types';
import { BarList } from '@/ui/BarList';
import { InlineMarkdown } from '@/ui/Markdown';
import { Muted, Row } from '@/ui/primitives';
import { formatBytes, formatCalendarDate, formatDate, formatNumber, formatPercent } from '@/utils/format';
import { useDebouncedValue } from 'core-react';
import { Icon } from 'core-react/icons';
import { Button, Card, CardBody, EmptyState, Pagination, SegmentedControl, TextField } from 'core-react/ui';
import { useEffect, useState } from 'react';
import { styled } from 'styled-components';

/** Analyse d'une question : rendu adapté à chacun des 7 types (specs_fonc §4 et §7.2). */
export const QuestionStatsCard = ({ surveyId, question }: { surveyId: string; question: QuestionStats }) => (
  <Card $bordered>
    <CardBody>
      <Header>
        <div>
          <Muted>
            Question {question.position} · <Icon name={QUESTION_TYPE_ICONS[question.type]} size={14} /> {QUESTION_TYPE_LABELS[question.type]}
            {!question.required && ' · facultative'}
          </Muted>
          <h3>
            <InlineMarkdown source={question.label} />
          </h3>
        </div>
        <Muted>
          {question.answersCount} réponse{question.answersCount > 1 ? 's' : ''} ({formatPercent(question.answerRate)} des répondants)
        </Muted>
      </Header>

      {question.answersCount === 0 ? (
        <Muted>Aucune réponse pour le moment.</Muted>
      ) : (
        <>
          {question.type === 'TEXT' && <TextBlock surveyId={surveyId} questionId={question.id} stats={question.stats as TextStats} />}
          {question.type === 'NUMBER' && <NumberBlock stats={question.stats as NumberStats} unit={(question.config as NumberConfig).unit} />}
          {question.type === 'CHOICE' && <ChoiceBlock stats={question.stats as ChoiceStats} />}
          {question.type === 'DATE' && <DateBlock stats={question.stats as DateStats} />}
          {question.type === 'FILE' && <FileBlock surveyId={surveyId} questionId={question.id} stats={question.stats as FileStats} />}
          {question.type === 'BOOLEAN' && <BooleanBlock stats={question.stats as BooleanStats} />}
          {question.type === 'RANGE' && <RangeBlock stats={question.stats as RangeStats} />}
        </>
      )}
    </CardBody>
  </Card>
);

/** RANGE : moyenne, médiane, extrêmes, répartition cran par cran (ou histogramme pour une échelle fine). */
const RangeBlock = ({ stats }: { stats: RangeStats }) => {
  const unit = stats.unit ?? undefined;
  const value = (v: number | null) => (v === null ? '—' : formatRangeValue(v, unit));
  const endLabel = (v: number) => (v === stats.scaleMin && stats.minLabel ? ` (${stats.minLabel})` : v === stats.scaleMax && stats.maxLabel ? ` (${stats.maxLabel})` : '');

  return (
    <>
      <Metrics>
        <Metric label="Moyenne" value={value(stats.mean)} />
        <Metric label="Médiane" value={value(stats.median)} />
        <Metric label="Minimum" value={value(stats.min)} />
        <Metric label="Maximum" value={value(stats.max)} />
      </Metrics>
      {stats.distribution.length > 0 ? (
        <BarList
          label="Répartition des réponses"
          max={stats.count}
          items={stats.distribution.map((d) => ({
            key: String(d.value),
            label: `${formatRangeValue(d.value, unit)}${endLabel(d.value)}`,
            value: d.count,
            caption: `${d.count} · ${formatPercent(stats.count > 0 ? Math.round((d.count / stats.count) * 1000) / 10 : 0)}`,
          }))}
        />
      ) : (
        <BarList
          label="Histogramme des réponses"
          items={stats.histogram.map((b) => ({ key: `${b.from}-${b.to}`, label: b.from === b.to ? value(b.from) : `${value(b.from)} – ${value(b.to)}`, value: b.count }))}
        />
      )}
    </>
  );
};

/** BOOLEAN : effectif et pourcentage de chaque valeur, barre comparative des deux libellés. */
const BooleanBlock = ({ stats }: { stats: BooleanStats }) => (
  <>
    <Metrics>
      <Metric label={stats.trueLabel} value={`${stats.trueCount} · ${formatPercent(stats.truePercent)}`} />
      <Metric label={stats.falseLabel} value={`${stats.falseCount} · ${formatPercent(stats.falsePercent)}`} />
    </Metrics>
    <Split role="img" aria-label={`${stats.trueLabel} : ${formatPercent(stats.truePercent)}, ${stats.falseLabel} : ${formatPercent(stats.falsePercent)}`}>
      <SplitPart $tone="true" style={{ flexGrow: stats.trueCount }}>
        {stats.truePercent >= 12 && `${stats.trueLabel} ${formatPercent(stats.truePercent)}`}
      </SplitPart>
      <SplitPart $tone="false" style={{ flexGrow: stats.falseCount }}>
        {stats.falsePercent >= 12 && `${stats.falseLabel} ${formatPercent(stats.falsePercent)}`}
      </SplitPart>
    </Split>
    <Muted>Base : répondants à la question.</Muted>
  </>
);

const Metric = ({ label, value }: { label: string; value: string }) => (
  <MetricBox>
    <span>{label}</span>
    <strong>{value}</strong>
  </MetricBox>
);

/** TEXT : nombre de réponses, longueur moyenne, verbatims paginés avec recherche par mot-clé. */
const TextBlock = ({ surveyId, questionId, stats }: { surveyId: string; questionId: string; stats: TextStats }) => {
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const debounced = useDebouncedValue(query, 300);
  const { data, isLoading } = useAsync(() => fetchVerbatims(surveyId, questionId, { page, q: debounced }), [surveyId, questionId, page, debounced]);

  return (
    <>
      <Metrics>
        <Metric label="Réponses" value={String(stats.count)} />
        <Metric label="Longueur moyenne" value={`${formatNumber(stats.averageLength, 0)} caractères`} />
      </Metrics>
      <TextField aria-label="Rechercher dans les réponses" type="search" placeholder="Rechercher un mot-clé dans les réponses…" value={query} onChange={(e) => { setQuery(e.target.value); setPage(1); }} />
      {isLoading && !data ? (
        <Muted>Chargement des réponses…</Muted>
      ) : data && data.items.length === 0 ? (
        <Muted>Aucune réponse ne contient « {debounced} ».</Muted>
      ) : (
        <Verbatims>
          {data?.items.map((item) => (
            <li key={item.id}>
              <blockquote>{item.text}</blockquote>
              <Muted>{formatDate(item.createdAt)}</Muted>
            </li>
          ))}
        </Verbatims>
      )}
      {data && data.meta.pages > 1 && <Pagination currentPage={page} totalPages={data.meta.pages} onPageChange={setPage} siblingCount={1} />}
    </>
  );
};

/** NUMBER : minimum, maximum, moyenne, médiane, écart-type, histogramme par tranches. */
const NumberBlock = ({ stats, unit }: { stats: NumberStats; unit?: string }) => {
  const u = unit ? ` ${unit}` : '';
  const range = (from: number, to: number) => (from === to ? formatNumber(from) : `${formatNumber(from)} – ${formatNumber(to)}`);

  return (
    <>
      <Metrics>
        <Metric label="Minimum" value={`${formatNumber(stats.min)}${u}`} />
        <Metric label="Maximum" value={`${formatNumber(stats.max)}${u}`} />
        <Metric label="Moyenne" value={`${formatNumber(stats.mean)}${u}`} />
        <Metric label="Médiane" value={`${formatNumber(stats.median)}${u}`} />
        <Metric label="Écart-type" value={formatNumber(stats.standardDeviation)} />
      </Metrics>
      <BarList
        label="Histogramme des réponses"
        items={stats.histogram.map((bucket) => ({ key: `${bucket.from}-${bucket.to}`, label: `${range(bucket.from, bucket.to)}${u}`, value: bucket.count }))}
      />
    </>
  );
};

type ChoiceSort = 'order' | 'count';

/** CHOICE : décompte et pourcentage par option, barres horizontales triables. */
const ChoiceBlock = ({ stats }: { stats: ChoiceStats }) => {
  const [sort, setSort] = useState<ChoiceSort>('order');
  const options = sort === 'count' ? [...stats.options].sort((a, b) => b.count - a.count) : stats.options;

  return (
    <>
      <Row $justify="space-between">
        <Muted>{stats.multiple ? 'Choix multiple : le total peut dépasser 100 %.' : 'Choix unique.'} Base : répondants à la question.</Muted>
        <SegmentedControl
          aria-label="Tri des options"
          size="sm"
          value={sort}
          onChange={setSort}
          options={[
            { value: 'order', label: 'Ordre du questionnaire' },
            { value: 'count', label: 'Plus choisies' },
          ]}
        />
      </Row>
      <BarList
        label="Répartition des réponses"
        max={stats.count}
        items={options.map((option) => ({ key: option.id, label: option.label, value: option.count, caption: `${option.count} · ${formatPercent(option.percent)}` }))}
      />
    </>
  );
};

/** DATE : plus ancienne, plus récente, médiane, distribution chronologique. */
const DateBlock = ({ stats }: { stats: DateStats }) => {
  const periodLabel = (period: string) =>
    stats.granularity === 'month' ? new Date(`${period}-01T00:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }) : formatCalendarDate(period);

  return (
    <>
      <Metrics>
        <Metric label="Plus ancienne" value={formatCalendarDate(stats.earliest)} />
        <Metric label="Médiane" value={formatCalendarDate(stats.median)} />
        <Metric label="Plus récente" value={formatCalendarDate(stats.latest)} />
      </Metrics>
      <Muted>Distribution {stats.granularity === 'month' ? 'par mois' : 'par jour'}</Muted>
      <BarList label="Distribution chronologique" items={stats.distribution.map((d) => ({ key: d.period, label: periodLabel(d.period), value: d.count }))} />
    </>
  );
};

/** FILE : nombre de fichiers, poids cumulé, répartition par format, galerie, téléchargement. */
const FileBlock = ({ surveyId, questionId, stats }: { surveyId: string; questionId: string; stats: FileStats }) => {
  const [page, setPage] = useState(1);
  const { data } = useAsync(() => fetchQuestionFiles(surveyId, questionId, page), [surveyId, questionId, page]);

  return (
    <>
      <Metrics>
        <Metric label="Fichiers" value={String(stats.totalFiles)} />
        <Metric label="Poids cumulé" value={formatBytes(stats.totalSize)} />
      </Metrics>
      <BarList
        label="Répartition par format"
        items={stats.formats.map((format) => ({ key: format.extension, label: format.extension.toUpperCase(), value: format.count, caption: `${format.count} · ${formatBytes(format.size)}` }))}
      />
      {data && data.items.length === 0 ? (
        <EmptyState icon="paperclip" title="Aucun fichier" />
      ) : (
        <Gallery>
          {data?.items.map((asset) => <AssetTile key={asset.id} surveyId={surveyId} asset={asset} />)}
        </Gallery>
      )}
      {data && data.meta.pages > 1 && <Pagination currentPage={page} totalPages={data.meta.pages} onPageChange={setPage} />}
    </>
  );
};

const AssetTile = ({ surveyId, asset }: { surveyId: string; asset: Asset }) => {
  const [preview, setPreview] = useState<string | null>(null);

  useEffect(() => {
    if (!asset.image) return undefined;
    let url: string | null = null;
    let cancelled = false;

    fetchObjectUrl(assetPath(surveyId, asset.id))
      .then((objectUrl) => {
        url = objectUrl;
        if (!cancelled) setPreview(objectUrl);
      })
      .catch(() => undefined);

    return () => {
      cancelled = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [surveyId, asset.id, asset.image]);

  return (
    <Tile>
      <Thumb>{preview ? <img src={preview} alt={asset.originalName} /> : <Icon name={asset.mimeType === 'application/pdf' ? 'file-document-outline' : asset.image ? 'image-outline' : 'file-outline'} size={36} />}</Thumb>
      <TileName title={asset.originalName}>{asset.originalName}</TileName>
      <Muted>
        {formatBytes(asset.size)} · {asset.recipientEmail}
      </Muted>
      <Button $size="sm" $variant="ghost" onClick={() => void downloadAsset(surveyId, asset)}>
        <Icon name="download" size={16} /> Télécharger
      </Button>
    </Tile>
  );
};

const Header = styled.div`
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: ${({ theme }) => theme.spacing[2]};
  margin-bottom: ${({ theme }) => theme.spacing[4]};

  h3 {
    margin: ${({ theme }) => theme.spacing[1]} 0 0;
    font-size: 1.05rem;
  }
`;

const Metrics = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: ${({ theme }) => theme.spacing[3]};
  margin-bottom: ${({ theme }) => theme.spacing[4]};
`;

const MetricBox = styled.div`
  padding: ${({ theme }) => theme.spacing[3]};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.surfaceHover};

  span {
    display: block;
    font-size: 0.75rem;
    color: ${({ theme }) => theme.colors.text.muted};
  }

  strong {
    font-size: 1.15rem;
    font-variant-numeric: tabular-nums;
  }
`;

const Split = styled.div`
  display: flex;
  overflow: hidden;
  height: 36px;
  margin-bottom: ${({ theme }) => theme.spacing[2]};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.surfaceHover};
`;

const SplitPart = styled.div<{ $tone: 'true' | 'false' }>`
  display: flex;
  flex-basis: 0;
  align-items: center;
  justify-content: center;
  min-width: 0;
  overflow: hidden;
  white-space: nowrap;
  font-size: 0.8rem;
  font-weight: 600;
  background: ${({ theme, $tone }) => ($tone === 'true' ? theme.colors.primary.base : theme.colors.border.strong)};
  color: ${({ theme }) => theme.colors.white};
`;

const Verbatims = styled.ul`
  list-style: none;
  margin: 0 0 ${({ theme }) => theme.spacing[3]};
  padding: 0;

  li {
    padding: ${({ theme }) => theme.spacing[3]} 0;
    border-bottom: 1px solid ${({ theme }) => theme.colors.border.default};
  }

  blockquote {
    margin: 0 0 ${({ theme }) => theme.spacing[1]};
    white-space: pre-line;
  }
`;

const Gallery = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: ${({ theme }) => theme.spacing[3]};
  margin-top: ${({ theme }) => theme.spacing[4]};
`;

const Tile = styled.div`
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[1]};
  padding: ${({ theme }) => theme.spacing[2]};
  border: 1px solid ${({ theme }) => theme.colors.border.default};
  border-radius: ${({ theme }) => theme.radii.md};
  min-width: 0;
  font-size: 0.8rem;
`;

const Thumb = styled.div`
  display: grid;
  place-items: center;
  aspect-ratio: 4 / 3;
  border-radius: ${({ theme }) => theme.radii.sm};
  background: ${({ theme }) => theme.colors.background.surfaceHover};
  color: ${({ theme }) => theme.colors.text.muted};
  overflow: hidden;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
`;

const TileName = styled.strong`
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
`;
