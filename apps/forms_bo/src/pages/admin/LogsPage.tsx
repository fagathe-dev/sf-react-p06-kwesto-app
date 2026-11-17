import { useAsync } from '@/hooks/useAsync';
import { fetchLogs } from '@/services/api';
import type { LogChannel, LogEntry, LogLevel } from '@/types';
import { PageHeader, PageLoading } from '@/ui/Page';
import { Muted, Panel, Row } from '@/ui/primitives';
import { formatDateTime } from '@/utils/format';
import { useDebouncedValue } from 'core-react';
import { Alert, Badge, Button, ChoiceField, EmptyState, Pagination, TextField } from 'core-react/ui';
import { useState } from 'react';
import { styled } from 'styled-components';

const CHANNELS: Array<{ value: LogChannel | ''; label: string }> = [
  { value: '', label: 'Tous les canaux' },
  { value: 'mailer', label: 'mailer' },
  { value: 'cron', label: 'cron' },
  { value: 'app', label: 'app' },
  { value: 'security', label: 'security' },
];

const LEVELS: Array<LogLevel | ''> = ['', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'];

const LEVEL_BADGES: Record<LogLevel, 'info' | 'warning' | 'danger' | 'secondary'> = {
  INFO: 'info',
  WARNING: 'warning',
  ERROR: 'danger',
  CRITICAL: 'danger',
};

const isoDay = (offsetDays = 0) => new Date(Date.now() + offsetDays * 86_400_000).toISOString().slice(0, 10);

/**
 * Espace Administration — journaux applicatifs sans accès SSH (specs_fonc §10.2).
 * Filtres : canal, sévérité, période, recherche textuelle (jeton, identifiant de sondage, e-mail…).
 */
export const LogsPage = () => {
  const [channel, setChannel] = useState<LogChannel | ''>('');
  const [level, setLevel] = useState<LogLevel | ''>('');
  const [from, setFrom] = useState(isoDay(-6));
  const [to, setTo] = useState(isoDay());
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const debounced = useDebouncedValue(query, 400);

  const { data, error, isLoading } = useAsync(() => fetchLogs({ channel, level, from, to, q: debounced, page }), [channel, level, from, to, debounced, page]);
  const reset = (fn: () => void) => {
    fn();
    setPage(1);
  };

  return (
    <>
      <PageHeader title="Journaux applicatifs" subtitle="Canaux mailer, cron, app et security (31 jours consultables au maximum)." />
      <Panel aria-label="Filtres">
        <Filters>
          <ChoiceField label="Canal" value={channel} onChange={(e) => reset(() => setChannel(e.target.value as LogChannel | ''))}>
            {CHANNELS.map((c) => (
              <option key={c.value} value={c.value}>
                {c.label}
              </option>
            ))}
          </ChoiceField>
          <ChoiceField label="Sévérité" value={level} onChange={(e) => reset(() => setLevel(e.target.value as LogLevel | ''))}>
            {LEVELS.map((l) => (
              <option key={l} value={l}>
                {l ? `${l}${data ? ` (${data.levelCounts[l]})` : ''}` : 'Toutes'}
              </option>
            ))}
          </ChoiceField>
          <TextField label="Du" type="date" value={from} max={to} onChange={(e) => reset(() => setFrom(e.target.value))} />
          <TextField label="Au" type="date" value={to} min={from} onChange={(e) => reset(() => setTo(e.target.value))} />
          <TextField label="Recherche" type="search" placeholder="Jeton, identifiant, e-mail, message…" value={query} onChange={(e) => reset(() => setQuery(e.target.value))} />
        </Filters>
      </Panel>

      {error && <Alert variant="danger">{error}</Alert>}
      {isLoading && !data ? (
        <PageLoading />
      ) : data && data.items.length === 0 ? (
        <EmptyState icon="text-box-search-outline" title="Aucune entrée" description="Aucun journal ne correspond à ces filtres sur la période." />
      ) : (
        <>
          <Muted>{data?.meta.total ?? 0} entrée(s)</Muted>
          <Entries>{data?.items.map((entry) => <LogRow key={`${entry.source}-${entry.id}`} entry={entry} />)}</Entries>
          {data && data.meta.pages > 1 && <Pagination currentPage={page} totalPages={data.meta.pages} onPageChange={setPage} />}
        </>
      )}
    </>
  );
};

const LogRow = ({ entry }: { entry: LogEntry }) => {
  const [open, setOpen] = useState(false);

  return (
    <li>
      <Row $gap={2}>
        <Badge $variant={LEVEL_BADGES[entry.level]}>{entry.level}</Badge>
        <Badge $variant="neutral" $pill>
          {entry.channel}
        </Badge>
        <Muted>{formatDateTime(entry.timestamp)}</Muted>
        <Muted>{entry.source}</Muted>
      </Row>
      <Message>
        {entry.message || '(sans message)'} {entry.action && <code>{entry.action}</code>}
      </Message>
      <Button $size="sm" $variant="ghost" aria-expanded={open} onClick={() => setOpen(!open)}>
        {open ? 'Masquer le détail' : 'Voir le détail'}
      </Button>
      {open && <Pre>{JSON.stringify({ content: entry.content, context: entry.context }, null, 2)}</Pre>}
    </li>
  );
};

const Filters = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 0 ${({ theme }) => theme.spacing[3]};
`;

const Entries = styled.ul`
  list-style: none;
  margin: ${({ theme }) => theme.spacing[3]} 0;
  padding: 0;

  li {
    padding: ${({ theme }) => theme.spacing[3]};
    border-bottom: 1px solid ${({ theme }) => theme.colors.border.default};
    background: ${({ theme }) => theme.colors.background.surface};
  }
`;

const Message = styled.p`
  margin: ${({ theme }) => theme.spacing[1]} 0;

  code {
    font-size: 0.75rem;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;

const Pre = styled.pre`
  max-height: 360px;
  overflow: auto;
  padding: ${({ theme }) => theme.spacing[3]};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.surfaceHover};
  font-size: 0.75rem;
`;
