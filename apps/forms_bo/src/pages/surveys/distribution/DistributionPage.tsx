import { useAsync } from '@/hooks/useAsync';
import { addRecipients, closeSurvey, fetchRecipients, fetchSurvey, importRecipientsCsv, launchSurvey, removeRecipients } from '@/services/api';
import { errorMessage, violationsOf } from '@/services/errors';
import { CLOSE_REASON_LABELS, DEVICE_LABELS, RECIPIENT_STATUS_LABELS, type AddRecipientsResponse, type RecipientStatus, type Survey } from '@/types';
import { DateTimeField } from '@/ui/DateTimeField';
import { HelpBox } from '@/ui/HelpBox';
import { PageHeader, PageLoading } from '@/ui/Page';
import { Muted, Panel, Row, SectionTitle, Stack } from '@/ui/primitives';
import { RecipientStatusBadge, SurveyStatusBadge } from '@/ui/StatusBadge';
import { formatDateTime, formatDuration, toDateTimeLocal } from '@/utils/format';
import { useDebouncedValue } from 'core-react';
import { Icon } from 'core-react/icons';
import {
  Alert,
  Button,
  ChoiceField,
  ConfirmDialog,
  EmptyState,
  FileDropzone,
  IconButton,
  Pagination,
  StatCard,
  Table,
  Tbody,
  Td,
  TextareaField,
  TextField,
  Th,
  Thead,
  TRow,
  useToast,
} from 'core-react/ui';
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { styled } from 'styled-components';

const STATUSES: Array<RecipientStatus | ''> = ['', 'PENDING', 'SENT', 'OPENED', 'ANSWERED', 'BOUNCED'];

/** Module 2 — liste de diffusion et planification (specs_fonc §5), suivi unitaire des destinataires. */
export const DistributionPage = () => {
  const { id = '' } = useParams();
  const surveyState = useAsync(() => fetchSurvey(id), [id]);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState<RecipientStatus | ''>('');
  const [query, setQuery] = useState('');
  const debouncedQuery = useDebouncedValue(query, 300);
  const recipientsState = useAsync(() => fetchRecipients(id, { page, status, q: debouncedQuery }), [id, page, status, debouncedQuery]);

  const survey = surveyState.data?.survey;
  if (surveyState.isLoading && !survey) return <PageLoading />;
  if (surveyState.error || !survey) return <Alert variant="danger">{surveyState.error ?? 'Questionnaire introuvable.'}</Alert>;

  const counters = recipientsState.data?.counters ?? surveyState.data!.recipients;
  const refresh = async () => {
    await Promise.all([surveyState.reload(), recipientsState.reload()]);
  };

  return (
    <>
      <PageHeader
        eyebrow={<SurveyStatusBadge status={survey.status} />}
        title={survey.title}
        subtitle={survey.status === 'VALIDATED' ? 'Liste de diffusion et date limite de réponse' : 'Suivi des destinataires'}
        actions={
          <>
            <Button href={`/surveys/${survey.id}`} $variant="ghost">
              Structure
            </Button>
            {(survey.status === 'ACTIVE' || survey.status === 'CLOSED') && <Button href={`/surveys/${survey.id}/stats`}>Statistiques</Button>}
          </>
        }
      />

      <Stack $gap={5}>
        {survey.status === 'DRAFT' && (
          <HelpBox tone="warning" title="Questionnaire non validé">
            La diffusion n'est accessible qu'une fois le questionnaire validé. Terminez sa conception puis cliquez sur « Valider le questionnaire ».
          </HelpBox>
        )}

        <Kpis>
          <StatCard label="Destinataires" value={counters.total} icon="account-group-outline" />
          <StatCard label="Envoyés" value={counters.sent + counters.opened + counters.answered} icon="send" />
          <StatCard label="Ouverts sans réponse" value={counters.opened} icon="eye-outline" />
          <StatCard label="Réponses" value={counters.answered} icon="check-circle-outline" />
          <StatCard label="Adresses rejetées" value={counters.bounced} icon="alert-circle-outline" />
        </Kpis>

        {survey.status === 'VALIDATED' && (
          <>
            <AudiencePanel surveyId={survey.id} onChange={refresh} />
            <LaunchPanel survey={survey} recipients={counters.total} onLaunched={refresh} />
          </>
        )}
        {survey.status === 'ACTIVE' && <ActivePanel survey={survey} onClosed={refresh} />}
        {survey.status === 'CLOSED' && (
          <HelpBox title="Campagne clôturée">
            Clôturée le {formatDateTime(survey.closedAt)} — {survey.closeReason ? CLOSE_REASON_LABELS[survey.closeReason] : ''}. Les réponses ne sont plus acceptées.
          </HelpBox>
        )}

        <Panel aria-label="Destinataires">
          <Row $justify="space-between">
            <SectionTitle>Destinataires</SectionTitle>
            <Row $gap={2}>
              <ChoiceField aria-label="Filtrer par statut" value={status} onChange={(e) => { setStatus(e.target.value as RecipientStatus | ''); setPage(1); }}>
                {STATUSES.map((s) => (
                  <option key={s} value={s}>
                    {s ? RECIPIENT_STATUS_LABELS[s] : 'Tous les statuts'}
                  </option>
                ))}
              </ChoiceField>
              <TextField aria-label="Rechercher une adresse" type="search" placeholder="Rechercher une adresse…" value={query} onChange={(e) => { setQuery(e.target.value); setPage(1); }} />
            </Row>
          </Row>

          {recipientsState.error && <Alert variant="danger">{recipientsState.error}</Alert>}
          {recipientsState.data && recipientsState.data.items.length === 0 ? (
            <EmptyState icon="email-outline" title={counters.total === 0 ? 'Aucun destinataire' : 'Aucun destinataire ne correspond'} description={survey.status === 'VALIDATED' ? 'Ajoutez des adresses ci-dessus.' : undefined} />
          ) : (
            <TableWrap>
              <Table $hoverable $dense>
                <Thead>
                  <TRow>
                    <Th>Adresse</Th>
                    <Th>Statut</Th>
                    <Th>Envoi</Th>
                    <Th>Ouverture</Th>
                    <Th>Réponse</Th>
                    <Th>Durée</Th>
                    <Th>Appareil</Th>
                    {survey.status === 'VALIDATED' && <Th aria-label="Actions" />}
                  </TRow>
                </Thead>
                <Tbody>
                  {recipientsState.data?.items.map((recipient) => (
                    <TRow key={recipient.id}>
                      <Td>
                        {recipient.email}
                        {recipient.status === 'BOUNCED' && recipient.lastError && <ErrorLine>{recipient.lastError}</ErrorLine>}
                      </Td>
                      <Td>
                        <RecipientStatusBadge status={recipient.status} />
                      </Td>
                      <Td>{formatDateTime(recipient.sentAt)}</Td>
                      <Td>{formatDateTime(recipient.openedAt)}</Td>
                      <Td>{formatDateTime(recipient.submittedAt)}</Td>
                      <Td>{formatDuration(recipient.completionSeconds)}</Td>
                      <Td>
                        {recipient.deviceType ? `${DEVICE_LABELS[recipient.deviceType]} · ${recipient.browserName ?? '?'} ${recipient.browserVersion ?? ''} · ${recipient.osName ?? '?'}` : '—'}
                      </Td>
                      {survey.status === 'VALIDATED' && (
                        <Td>
                          <IconButton
                            name="close"
                            size="sm"
                            variant="ghost"
                            aria-label={`Retirer ${recipient.email}`}
                            onClick={() => void removeRecipients(survey.id, [recipient.id]).then(refresh)}
                          />
                        </Td>
                      )}
                    </TRow>
                  ))}
                </Tbody>
              </Table>
            </TableWrap>
          )}
          {recipientsState.data && recipientsState.data.meta.pages > 1 && (
            <Pagination currentPage={page} totalPages={recipientsState.data.meta.pages} onPageChange={setPage} />
          )}
        </Panel>
      </Stack>
    </>
  );
};

/** Saisie de l'audience : multi-lignes, copier-coller ou CSV (specs_fonc §5.1). */
const AudiencePanel = ({ surveyId, onChange }: { surveyId: string; onChange: () => Promise<void> }) => {
  const { success } = useToast();
  const [emails, setEmails] = useState('');
  const [busy, setBusy] = useState(false);
  const [report, setReport] = useState<AddRecipientsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [confirmClear, setConfirmClear] = useState(false);

  const handle = async (action: () => Promise<AddRecipientsResponse>) => {
    setBusy(true);
    setError(null);
    try {
      const result = await action();
      setReport(result);
      if (result.added > 0) success(`${result.added} adresse${result.added > 1 ? 's' : ''} ajoutée${result.added > 1 ? 's' : ''}.`);
      setEmails('');
      await onChange();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Panel aria-label="Ajouter des destinataires">
      <SectionTitle>Ajouter des destinataires</SectionTitle>
      <Columns>
        <div>
          <TextareaField
            label="Adresses e-mail"
            rows={6}
            value={emails}
            onChange={(e) => setEmails(e.target.value)}
            placeholder={'prenom.nom@exemple.fr\nautre.adresse@exemple.fr'}
            hint="Une adresse par ligne, ou séparées par des virgules, points-virgules ou espaces."
          />
          <Row $gap={2}>
            <Button $loading={busy} disabled={emails.trim() === ''} onClick={() => void handle(() => addRecipients(surveyId, emails))}>
              Ajouter à la liste
            </Button>
            <Button $variant="ghost" onClick={() => setConfirmClear(true)}>
              Vider la liste
            </Button>
          </Row>
        </div>
        <div>
          <FileDropzone
            label="Importer un fichier CSV"
            accept=".csv,text/csv,text/plain"
            maxSizeMb={2}
            hint="Les adresses sont repérées dans toutes les colonnes ; les en-têtes sont ignorés."
            onFilesSelected={(files) => files[0] && void handle(() => importRecipientsCsv(surveyId, files[0]!))}
            disabled={busy}
          />
        </div>
      </Columns>
      <Muted>Nettoyage automatique : minuscules, espaces retirés, validation de la syntaxe, doublons supprimés.</Muted>
      {error && <Alert variant="danger">{error}</Alert>}
      {report && (
        <Alert variant={report.invalid.length > 0 ? 'warning' : 'success'} onClose={() => setReport(null)} title={`${report.added} adresse(s) ajoutée(s)`}>
          {report.alreadyPresent > 0 && <div>{report.alreadyPresent} déjà présente(s) dans la liste.</div>}
          {report.duplicates > 0 && <div>{report.duplicates} doublon(s) ignoré(s) dans la saisie.</div>}
          {report.overLimit > 0 && <div>{report.overLimit} adresse(s) au-delà de la limite de 5 000 destinataires.</div>}
          {report.invalid.length > 0 && (
            <div>
              {report.invalid.length} adresse(s) invalide(s) : <code>{report.invalid.slice(0, 10).join(', ')}</code>
              {report.invalid.length > 10 ? '…' : ''}
            </div>
          )}
        </Alert>
      )}
      <ConfirmDialog
        isOpen={confirmClear}
        onClose={() => setConfirmClear(false)}
        onConfirm={() => {
          setConfirmClear(false);
          void removeRecipients(surveyId, 'all').then(onChange);
        }}
        title="Vider la liste de diffusion ?"
        message="Toutes les adresses saisies seront retirées."
        confirmLabel="Vider la liste"
      />
    </Panel>
  );
};

/** Date limite (strictement future) puis lancement : jetons générés, ACTIVE, invitations envoyées. */
const LaunchPanel = ({ survey, recipients, onLaunched }: { survey: Survey; recipients: number; onLaunched: () => Promise<void> }) => {
  const { success } = useToast();
  const [expiresAt, setExpiresAt] = useState(() => toDateTimeLocal(new Date(Date.now() + 14 * 24 * 3600 * 1000)));
  const [confirm, setConfirm] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [launching, setLaunching] = useState(false);

  // Instant de référence figé au montage (le rendu doit rester pur) ; le serveur revérifie au lancement
  const [mountedAt] = useState(() => Date.now());
  const isFuture = expiresAt !== '' && new Date(expiresAt).getTime() > mountedAt;

  const launch = async () => {
    setConfirm(false);
    setLaunching(true);
    setError(null);
    try {
      await launchSurvey(survey.id, new Date(expiresAt).toISOString());
      success('Les invitations sont en cours d\'envoi.', 'Campagne lancée');
      await onLaunched();
    } catch (err) {
      setError(violationsOf(err).expiresAt ?? errorMessage(err));
    } finally {
      setLaunching(false);
    }
  };

  return (
    <Panel aria-label="Lancement de la campagne">
      <SectionTitle>Planification et lancement</SectionTitle>
      <LaunchRow>
        <DateTimeField
          label="Date limite de réponse"
          required
          value={expiresAt}
          min={toDateTimeLocal(new Date())}
          onChange={(e) => setExpiresAt(e.target.value)}
          error={expiresAt && !isFuture ? 'La date limite doit être dans le futur.' : undefined}
          hint="Le questionnaire sera clôturé automatiquement à cette date et heure."
        />
        <Button $size="lg" disabled={!isFuture || recipients === 0} $loading={launching} onClick={() => setConfirm(true)}>
          <Icon name="send" size={18} /> Lancer la campagne
        </Button>
      </LaunchRow>
      {recipients === 0 && <Muted>Ajoutez au moins un destinataire pour lancer la campagne.</Muted>}
      {error && <Alert variant="danger">{error}</Alert>}
      <ConfirmDialog
        isOpen={confirm}
        onClose={() => setConfirm(false)}
        onConfirm={() => void launch()}
        title="Lancer la campagne ?"
        message={`Un lien personnel sera envoyé à ${recipients} destinataire(s). La liste de diffusion ne sera plus modifiable.`}
        confirmLabel="Envoyer les invitations"
        variant="primary"
      />
    </Panel>
  );
};

const ActivePanel = ({ survey, onClosed }: { survey: Survey; onClosed: () => Promise<void> }) => {
  const { success } = useToast();
  const [confirm, setConfirm] = useState(false);
  const [error, setError] = useState<string | null>(null);

  return (
    <Panel aria-label="Campagne en cours">
      <Row $justify="space-between">
        <div>
          <SectionTitle>Campagne en cours</SectionTitle>
          <Muted>
            Lancée le {formatDateTime(survey.activatedAt)} · clôture automatique le {formatDateTime(survey.expiresAt)}
            {survey.reminderSentAt ? ` · relance envoyée le ${formatDateTime(survey.reminderSentAt)} (${survey.remindersCount})` : ''}
          </Muted>
        </div>
        <Button $variant="danger" onClick={() => setConfirm(true)}>
          Clôturer maintenant
        </Button>
      </Row>
      {error && <Alert variant="danger">{error}</Alert>}
      <ConfirmDialog
        isOpen={confirm}
        onClose={() => setConfirm(false)}
        onConfirm={() => {
          setConfirm(false);
          closeSurvey(survey.id)
            .then(() => success('Les statistiques sont désormais consolidées.', 'Campagne clôturée'))
            .then(onClosed)
            .catch((err: unknown) => setError(errorMessage(err)));
        }}
        title="Clôturer la campagne ?"
        message="Les liens des participants n'accepteront plus de réponse. Cette action est définitive."
        confirmLabel="Clôturer"
      />
    </Panel>
  );
};

const Kpis = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: ${({ theme }) => theme.spacing[3]};
`;

const Columns = styled.div`
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: ${({ theme }) => theme.spacing[5]};
  margin: ${({ theme }) => theme.spacing[3]} 0;
`;

const LaunchRow = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[4]};
  margin-top: ${({ theme }) => theme.spacing[3]};
`;

const TableWrap = styled.div`
  overflow-x: auto;
  margin-top: ${({ theme }) => theme.spacing[3]};
`;

const ErrorLine = styled.div`
  font-size: 0.75rem;
  color: ${({ theme }) => theme.colors.danger.base};
`;
