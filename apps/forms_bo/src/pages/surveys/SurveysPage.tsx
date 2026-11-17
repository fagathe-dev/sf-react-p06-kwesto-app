import { useAsync } from '@/hooks/useAsync';
import { createSurvey, fetchSurveys } from '@/services/api';
import { errorMessage, violationsOf } from '@/services/errors';
import { STATUS_LABELS, type SurveyListItem, type SurveyStatus } from '@/types';
import { PageHeader, PageLoading } from '@/ui/Page';
import { Muted, Row, Stack } from '@/ui/primitives';
import { SurveyStatusBadge } from '@/ui/StatusBadge';
import { formatAgo, formatDate } from '@/utils/format';
import { useDebouncedValue } from 'core-react';
import { Icon } from 'core-react/icons';
import {
  Alert,
  Button,
  Card,
  CardBody,
  EmptyState,
  Modal,
  ModalBody,
  ModalFooter,
  ModalHeader,
  Pagination,
  SegmentedControl,
  TextField,
  TextareaField,
} from 'core-react/ui';
import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { styled } from 'styled-components';

type Filter = SurveyStatus | 'ALL';

const FILTERS: Filter[] = ['ALL', 'DRAFT', 'VALIDATED', 'ACTIVE', 'CLOSED'];

/** Page d'accueil du créateur : ses questionnaires, filtrés par état du cycle de vie. */
export const SurveysPage = () => {
  const [filter, setFilter] = useState<Filter>('ALL');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const debouncedQuery = useDebouncedValue(query, 300);

  const { data, error, isLoading } = useAsync(
    () => fetchSurveys({ page, status: filter === 'ALL' ? '' : filter, q: debouncedQuery }),
    [page, filter, debouncedQuery]
  );

  const total = data ? Object.values(data.counts).reduce((sum, n) => sum + n, 0) : 0;
  const options = FILTERS.map((value) => ({
    value,
    label: `${value === 'ALL' ? 'Tous' : STATUS_LABELS[value]}${data ? ` (${value === 'ALL' ? total : data.counts[value]})` : ''}`,
  }));

  return (
    <>
      <PageHeader
        title="Mes questionnaires"
        subtitle="Concevez, diffusez et analysez vos campagnes."
        actions={
          <Button onClick={() => setCreating(true)}>
            <Icon name="plus" size={18} /> Nouveau questionnaire
          </Button>
        }
      />

      <Toolbar>
        <SegmentedControl
          aria-label="Filtrer par état"
          size="sm"
          options={options}
          value={filter}
          onChange={(value) => {
            setFilter(value);
            setPage(1);
          }}
        />
        <Search>
          <TextField
            aria-label="Filtrer par titre"
            type="search"
            placeholder="Filtrer par titre ou description…"
            value={query}
            onChange={(event) => {
              setQuery(event.target.value);
              setPage(1);
            }}
          />
        </Search>
      </Toolbar>

      {error && <Alert variant="danger">{error}</Alert>}
      {isLoading && !data ? (
        <PageLoading />
      ) : data && data.items.length === 0 ? (
        <EmptyState
          icon="file-document-plus-outline"
          title={filter === 'ALL' && !debouncedQuery ? 'Aucun questionnaire pour le moment' : 'Aucun questionnaire ne correspond'}
          description="Créez votre premier questionnaire : ajoutez des questions, validez-le puis diffusez-le."
          action={<Button onClick={() => setCreating(true)}>Créer un questionnaire</Button>}
        />
      ) : (
        <Stack $gap={3}>
          {data?.items.map((item) => <SurveyCard key={item.survey.id} item={item} />)}
          {data && data.meta.pages > 1 && <Pagination currentPage={page} totalPages={data.meta.pages} onPageChange={setPage} />}
        </Stack>
      )}

      <CreateSurveyModal isOpen={creating} onClose={() => setCreating(false)} />
    </>
  );
};

const SurveyCard = ({ item }: { item: SurveyListItem }) => {
  const { survey, recipients } = item;
  const target = survey.status === 'VALIDATED' ? 'recipients' : survey.status === 'DRAFT' ? '' : 'stats';
  const rate = recipients.valid > 0 ? Math.round((recipients.answered / recipients.valid) * 100) : 0;

  return (
    <Card $bordered>
      <CardBody>
        <CardLayout>
          <div>
            <Row $gap={2}>
              <SurveyStatusBadge status={survey.status} />
              <Muted>Modifié {formatAgo(survey.updatedAt)}</Muted>
            </Row>
            <TitleLink to={`/surveys/${survey.id}${target ? `/${target}` : ''}`}>{survey.title}</TitleLink>
            <Row $gap={4}>
              <Muted>
                <Icon name="format-list-checks" size={16} /> {survey.questionsCount} question{survey.questionsCount > 1 ? 's' : ''}
              </Muted>
              {recipients.total > 0 && (
                <Muted>
                  <Icon name="account-group-outline" size={16} /> {recipients.total} destinataire{recipients.total > 1 ? 's' : ''}
                </Muted>
              )}
              {survey.expiresAt && (
                <Muted>
                  <Icon name="calendar-clock" size={16} /> {survey.status === 'CLOSED' ? 'Clôturé le' : 'Échéance'}{' '}
                  {formatDate(survey.status === 'CLOSED' ? survey.closedAt : survey.expiresAt)}
                </Muted>
              )}
            </Row>
          </div>
          <Aside>
            {(survey.status === 'ACTIVE' || survey.status === 'CLOSED') && (
              <Rate aria-label={`Taux de participation : ${rate} %`}>
                <strong>{rate} %</strong>
                <span>
                  {recipients.answered}/{recipients.valid} réponses
                </span>
              </Rate>
            )}
            <Row $gap={2}>
              {survey.status === 'DRAFT' && <Button href={`/surveys/${survey.id}`} $size="sm">Continuer la conception</Button>}
              {survey.status === 'VALIDATED' && <Button href={`/surveys/${survey.id}/recipients`} $size="sm">Configurer la diffusion</Button>}
              {(survey.status === 'ACTIVE' || survey.status === 'CLOSED') && (
                <>
                  <Button href={`/surveys/${survey.id}/recipients`} $size="sm" $variant="ghost">
                    Destinataires
                  </Button>
                  <Button href={`/surveys/${survey.id}/stats`} $size="sm">
                    Statistiques
                  </Button>
                </>
              )}
            </Row>
          </Aside>
        </CardLayout>
      </CardBody>
    </Card>
  );
};

const CreateSurveyModal = ({ isOpen, onClose }: { isOpen: boolean; onClose: () => void }) => {
  const navigate = useNavigate();
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [apiError, setApiError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setErrors({});
    setApiError(null);

    if (title.trim().length < 3 || title.trim().length > 150) {
      setErrors({ title: 'Le titre doit contenir entre 3 et 150 caractères.' });
      return;
    }

    setSaving(true);
    try {
      const { survey } = await createSurvey(title.trim(), description.trim() || undefined);
      navigate(`/surveys/${survey.id}`);
    } catch (error) {
      setErrors(violationsOf(error));
      setApiError(errorMessage(error));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose}>
      <form onSubmit={submit} noValidate>
        <ModalHeader title="Nouveau questionnaire" onClose={onClose} />
        <ModalBody>
          {apiError && !errors.title && <Alert variant="danger">{apiError}</Alert>}
          <TextField label="Titre" required maxLength={150} value={title} onChange={(e) => setTitle(e.target.value)} error={errors.title} hint="Entre 3 et 150 caractères." />
          <TextareaField
            label="Description / consignes"
            rows={4}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            hint="Facultatif. Affichée en en-tête du formulaire participant."
          />
        </ModalBody>
        <ModalFooter>
          <Button type="button" $variant="ghost" onClick={onClose}>
            Annuler
          </Button>
          <Button type="submit" $loading={saving}>
            Créer et concevoir
          </Button>
        </ModalFooter>
      </form>
    </Modal>
  );
};

const Toolbar = styled.div`
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: ${({ theme }) => theme.spacing[3]};
  margin-bottom: ${({ theme }) => theme.spacing[4]};
`;

const Search = styled.div`
  flex: 1;
  min-width: 220px;
  max-width: 360px;

  & > * {
    margin-bottom: 0;
  }
`;

const CardLayout = styled.div`
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: ${({ theme }) => theme.spacing[4]};
`;

const TitleLink = styled(Link)`
  display: block;
  margin: ${({ theme }) => theme.spacing[2]} 0;
  font-size: 1.15rem;
  font-weight: 600;
  color: ${({ theme }) => theme.colors.text.heading};
  text-decoration: none;

  &:hover {
    color: ${({ theme }) => theme.colors.primary.base};
  }
`;

const Aside = styled.div`
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  justify-content: space-between;
  gap: ${({ theme }) => theme.spacing[3]};
`;

const Rate = styled.div`
  text-align: right;

  strong {
    display: block;
    font-size: 1.4rem;
    color: ${({ theme }) => theme.colors.primary.base};
  }

  span {
    font-size: 0.8rem;
    color: ${({ theme }) => theme.colors.text.muted};
  }
`;
