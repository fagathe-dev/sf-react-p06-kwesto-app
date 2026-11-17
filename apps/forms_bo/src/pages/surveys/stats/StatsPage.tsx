import { useAsync } from '@/hooks/useAsync';
import { downloadArchive, downloadExport, fetchStats } from '@/services/api';
import { errorMessage } from '@/services/errors';
import { CLOSE_REASON_LABELS, DEVICE_LABELS, type CountItem, type DeviceType } from '@/types';
import { BarList } from '@/ui/BarList';
import { HelpBox } from '@/ui/HelpBox';
import { PageHeader, PageLoading } from '@/ui/Page';
import { Muted, Panel, SectionTitle, Stack } from '@/ui/primitives';
import { SurveyStatusBadge } from '@/ui/StatusBadge';
import { formatDateTime, formatDuration, formatPercent } from '@/utils/format';
import { Icon } from 'core-react/icons';
import { Alert, Button, StatCard, useToast } from 'core-react/ui';
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { styled } from 'styled-components';
import { QuestionStatsCard } from './QuestionStatsCard';

/** Module 4 — tableau de bord statistique (specs_fonc §7.2). */
export const StatsPage = () => {
  const { id = '' } = useParams();
  const { error: toastError } = useToast();
  const [refresh, setRefresh] = useState(0);
  const { data, error, isLoading } = useAsync(() => fetchStats(id, refresh > 0), [id, refresh]);
  const [downloading, setDownloading] = useState<'csv' | 'zip' | null>(null);

  if (isLoading && !data) return <PageLoading label="Calcul des statistiques…" />;
  if (error || !data) return <Alert variant="danger">{error ?? 'Statistiques indisponibles.'}</Alert>;

  const { survey, stats } = data;
  const { global } = stats;
  const hasFiles = stats.questions.some((q) => q.type === 'FILE' && q.answersCount > 0);

  const download = async (kind: 'csv' | 'zip') => {
    setDownloading(kind);
    try {
      await (kind === 'csv' ? downloadExport(survey.id) : downloadArchive(survey.id));
    } catch (err) {
      toastError(errorMessage(err), 'Téléchargement impossible');
    } finally {
      setDownloading(null);
    }
  };

  return (
    <>
      <PageHeader
        eyebrow={<SurveyStatusBadge status={survey.status} />}
        title={survey.title}
        subtitle={
          stats.frozen
            ? `Statistiques consolidées et figées · ${survey.closeReason ? CLOSE_REASON_LABELS[survey.closeReason] : ''} le ${formatDateTime(survey.closedAt)}`
            : `Résultats partiels en temps réel · calculés le ${formatDateTime(stats.computedAt)}`
        }
        actions={
          <>
            <Button href={`/surveys/${survey.id}/recipients`} $variant="ghost">
              Destinataires
            </Button>
            {!stats.frozen && (
              <Button $variant="outline" onClick={() => setRefresh((n) => n + 1)} $loading={isLoading}>
                <Icon name="restore" size={18} /> Actualiser
              </Button>
            )}
            <Button $variant="outline" onClick={() => void download('csv')} $loading={downloading === 'csv'}>
              <Icon name="download" size={18} /> Export CSV / Excel
            </Button>
            {hasFiles && (
              <Button $variant="outline" onClick={() => void download('zip')} $loading={downloading === 'zip'}>
                <Icon name="archive-arrow-down-outline" size={18} /> Fichiers (.zip)
              </Button>
            )}
          </>
        }
      />

      <Stack $gap={5}>
        {!stats.frozen && (
          <HelpBox title="Campagne en cours">
            Ces résultats évoluent à chaque nouvelle réponse. Ils seront consolidés et figés à la clôture du questionnaire.
          </HelpBox>
        )}

        <Kpis>
          <StatCard label="Taux de participation" value={formatPercent(global.participationRate)} icon="target" trend={`${global.answered} / ${global.valid} invités valides`} trendVariant="neutral" />
          <StatCard label="Ouverts sans réponse" value={formatPercent(global.openedNotAnsweredRate)} icon="eye-outline" trend={`${global.opened} destinataire(s)`} trendVariant="neutral" />
          <StatCard label="Temps moyen de remplissage" value={formatDuration(global.averageCompletionSeconds)} icon="clock-outline" />
          <StatCard label="Adresses rejetées" value={global.bounced} icon="alert-circle-outline" trend="exclues du calcul" trendVariant="neutral" />
        </Kpis>

        <Panel aria-label="Répartition technique des répondants">
          <SectionTitle>Répartition technique des répondants</SectionTitle>
          {global.answered === 0 ? (
            <Muted>Aucun répondant pour le moment.</Muted>
          ) : (
            <Tech>
              <Breakdown title="Appareils" items={global.devices.map((d) => ({ ...d, label: DEVICE_LABELS[d.label as DeviceType] ?? d.label }))} total={global.answered} />
              <Breakdown title="Navigateurs" items={global.browsers} total={global.answered} />
              <Breakdown title="Systèmes" items={global.operatingSystems} total={global.answered} />
            </Tech>
          )}
        </Panel>

        <section aria-label="Analyse question par question">
          <SectionTitle>Analyse question par question</SectionTitle>
          <Stack $gap={3}>
            <span />
            {stats.questions.map((question) => (
              <QuestionStatsCard key={question.id} surveyId={survey.id} question={question} />
            ))}
          </Stack>
        </section>
      </Stack>
    </>
  );
};

const Breakdown = ({ title, items, total }: { title: string; items: CountItem[]; total: number }) => (
  <div>
    <h3>{title}</h3>
    <BarList
      label={title}
      max={total}
      items={items.map((item) => ({ key: item.label, label: item.label, value: item.count, caption: `${item.count} · ${formatPercent(total > 0 ? Math.round((item.count / total) * 1000) / 10 : 0)}` }))}
    />
  </div>
);

const Kpis = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: ${({ theme }) => theme.spacing[3]};
`;

const Tech = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: ${({ theme }) => theme.spacing[5]};
  margin-top: ${({ theme }) => theme.spacing[3]};

  h3 {
    margin: 0 0 ${({ theme }) => theme.spacing[2]};
    font-size: 0.9rem;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;
