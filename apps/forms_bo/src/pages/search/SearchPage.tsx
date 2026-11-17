import { useAsync } from '@/hooks/useAsync';
import { search, type SearchResponse } from '@/services/api';
import { PageHeader, PageLoading } from '@/ui/Page';
import { Muted, Panel, SectionTitle, Stack } from '@/ui/primitives';
import { RecipientStatusBadge, SurveyStatusBadge } from '@/ui/StatusBadge';
import { Alert, EmptyState } from 'core-react/ui';
import type { ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { styled } from 'styled-components';

const EMPTY: SearchResponse = { query: '', surveys: [], questions: [], recipients: [], verbatims: [] };

/** Recherche plein texte : questionnaires, questions, destinataires et verbatims, par pertinence. */
export const SearchPage = () => {
  const [params] = useSearchParams();
  const query = params.get('q')?.trim() ?? '';
  const { data, error, isLoading } = useAsync(() => (query.length >= 2 ? search(query) : Promise.resolve(EMPTY)), [query]);

  const results = data ?? EMPTY;
  const total = results.surveys.length + results.questions.length + results.recipients.length + results.verbatims.length;

  return (
    <>
      <PageHeader title="Recherche" subtitle={query ? `Résultats pour « ${query} »` : 'Saisissez au moins 2 caractères dans la barre de recherche.'} />
      {error && <Alert variant="danger">{error}</Alert>}
      {isLoading ? (
        <PageLoading />
      ) : query.length >= 2 && total === 0 ? (
        <EmptyState icon="magnify" title="Aucun résultat" description="Essayez un autre mot-clé. Les mots de moins de 3 lettres sont recherchés par fragment." />
      ) : (
        <Stack $gap={4}>
          <Group title="Questionnaires" count={results.surveys.length}>
            {results.surveys.map((survey) => (
              <li key={survey.id}>
                <Link to={`/surveys/${survey.id}`}>{survey.title}</Link> <SurveyStatusBadge status={survey.status} />
                {survey.description && <Excerpt>{survey.description}</Excerpt>}
              </li>
            ))}
          </Group>
          <Group title="Questions" count={results.questions.length}>
            {results.questions.map((question) => (
              <li key={question.id}>
                <Link to={`/surveys/${question.surveyId}`}>
                  Q{question.position}. {question.label}
                </Link>
                <Muted> — {question.surveyTitle}</Muted>
              </li>
            ))}
          </Group>
          <Group title="Destinataires" count={results.recipients.length}>
            {results.recipients.map((recipient) => (
              <li key={recipient.id}>
                <Link to={`/surveys/${recipient.surveyId}/recipients?q=${encodeURIComponent(recipient.email)}`}>{recipient.email}</Link>{' '}
                <RecipientStatusBadge status={recipient.status} />
                <Muted> — {recipient.surveyTitle}</Muted>
              </li>
            ))}
          </Group>
          <Group title="Réponses (verbatims)" count={results.verbatims.length}>
            {results.verbatims.map((verbatim) => (
              <li key={verbatim.id}>
                <Excerpt>« {verbatim.text} »</Excerpt>
                <Muted>
                  <Link to={`/surveys/${verbatim.surveyId}/stats`}>{verbatim.surveyTitle}</Link> · {verbatim.questionLabel}
                </Muted>
              </li>
            ))}
          </Group>
        </Stack>
      )}
    </>
  );
};

const Group = ({ title, count, children }: { title: string; count: number; children: ReactNode }) =>
  count === 0 ? null : (
    <Panel aria-label={title}>
      <SectionTitle>
        {title} <Muted>({count})</Muted>
      </SectionTitle>
      <List>{children}</List>
    </Panel>
  );

const List = styled.ul`
  list-style: none;
  margin: ${({ theme }) => theme.spacing[3]} 0 0;
  padding: 0;

  li {
    padding: ${({ theme }) => theme.spacing[2]} 0;
    border-bottom: 1px solid ${({ theme }) => theme.colors.border.default};
  }

  li:last-child {
    border-bottom: 0;
  }

  a {
    font-weight: 600;
    color: ${({ theme }) => theme.colors.primary.base};
  }
`;

const Excerpt = styled.p`
  margin: ${({ theme }) => theme.spacing[1]} 0;
  color: ${({ theme }) => theme.colors.text.body};
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
`;
