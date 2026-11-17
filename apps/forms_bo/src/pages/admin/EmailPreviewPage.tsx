import { useAsync } from '@/hooks/useAsync';
import { fetchEmailPreviews, renderEmailPreview, sendTestEmail } from '@/services/api';
import { errorMessage } from '@/services/errors';
import { useAuthStore } from '@/stores/auth.store';
import type { EmailPreview } from '@/types';
import { DateTimeField } from '@/ui/DateTimeField';
import { PageHeader, PageLoading } from '@/ui/Page';
import { Muted, Panel, Row, SectionTitle } from '@/ui/primitives';
import { useDebouncedValue } from 'core-react';
import { Icon } from 'core-react/icons';
import { Alert, Badge, Button, SegmentedControl, TextareaField, TextField, useToast } from 'core-react/ui';
import { useMemo, useState } from 'react';
import { styled } from 'styled-components';

type Viewport = 'desktop' | 'mobile';

/**
 * Espace Administration — prévisualisation des templates d'e-mails (specs_fonc §10.1) : rendu HTML
 * exact (desktop et mobile), jeu de données réaliste personnalisable, envoi d'un e-mail de test.
 */
export const EmailPreviewPage = () => {
  const { data, error, isLoading } = useAsync(fetchEmailPreviews, []);
  const [chosen, setSelected] = useState<string | null>(null);
  // Premier template sélectionné par défaut : état dérivé, sans effet
  const selected = chosen ?? data?.[0]?.slug ?? null;
  const preview = data?.find((item) => item.slug === selected) ?? null;

  if (isLoading && !data) return <PageLoading />;

  return (
    <>
      <PageHeader title="Templates d'e-mails" subtitle="Les e-mails transactionnels de kwesto et les templates prédéfinis de core-php." />
      {error && <Alert variant="danger">{error}</Alert>}
      <Layout>
        <Panel aria-label="Templates">
          <TemplateList>
            {data?.map((item) => (
              <li key={item.slug}>
                <TemplateButton type="button" $active={item.slug === selected} aria-pressed={item.slug === selected} onClick={() => setSelected(item.slug)}>
                  <strong>{item.label}</strong>
                  <code>{item.code}</code>
                  <Badge $variant={item.audience === 'Participant' ? 'info' : item.audience === 'Créateur' ? 'primary' : 'neutral'} $pill>
                    {item.audience}
                  </Badge>
                </TemplateButton>
              </li>
            ))}
          </TemplateList>
        </Panel>
        {preview && <PreviewPane key={preview.slug} preview={preview} />}
      </Layout>
    </>
  );
};

const PreviewPane = ({ preview }: { preview: EmailPreview }) => {
  const adminEmail = useAuthStore((state) => state.user?.email);
  const { success, error: toastError } = useToast();
  const defaults = useMemo(() => Object.fromEntries(preview.fields.map((f) => [f.name, f.value])), [preview]);
  const [values, setValues] = useState<Record<string, string>>(defaults);
  const debounced = useDebouncedValue(values, 400);
  const [viewport, setViewport] = useState<Viewport>('desktop');
  const [sending, setSending] = useState(false);
  const { data, error } = useAsync(() => renderEmailPreview(preview.slug, debounced), [preview.slug, debounced]);

  const sendTest = async () => {
    setSending(true);
    try {
      const { message } = await sendTestEmail(preview.slug, values);
      success(message, 'E-mail de test');
    } catch (err) {
      toastError(errorMessage(err), 'Envoi impossible');
    } finally {
      setSending(false);
    }
  };

  return (
    <Panel aria-label={`Aperçu : ${preview.label}`}>
      <Row $justify="space-between">
        <div>
          <SectionTitle>{preview.label}</SectionTitle>
          <Muted>{preview.description}</Muted>
        </div>
        <Row $gap={2}>
          <SegmentedControl
            aria-label="Largeur d'affichage"
            size="sm"
            value={viewport}
            onChange={setViewport}
            options={[
              { value: 'desktop', icon: 'monitor', title: 'Ordinateur' },
              { value: 'mobile', icon: 'cellphone', title: 'Mobile' },
            ]}
          />
          <Button $size="sm" onClick={() => void sendTest()} $loading={sending}>
            <Icon name="send" size={16} /> Envoyer un test
          </Button>
        </Row>
      </Row>
      <Muted>
        Objet : <strong>{data?.subject ?? preview.subject}</strong> · Le test est envoyé à {adminEmail}.
      </Muted>

      <Split>
        <Fields aria-label="Jeu de données">
          {preview.fields.map((field) =>
            field.type === 'textarea' ? (
              <TextareaField key={field.name} label={field.label} rows={4} value={values[field.name] ?? ''} onChange={(e) => setValues({ ...values, [field.name]: e.target.value })} />
            ) : (
              field.type === 'datetime-local' ? (
                <DateTimeField key={field.name} label={field.label} value={values[field.name] ?? ''} onChange={(e) => setValues({ ...values, [field.name]: e.target.value })} />
              ) : (
                <TextField key={field.name} label={field.label} type={field.type} value={values[field.name] ?? ''} onChange={(e) => setValues({ ...values, [field.name]: e.target.value })} />
              )
            )
          )}
          <Button $size="sm" $variant="ghost" onClick={() => setValues(defaults)}>
            Réinitialiser le jeu de données
          </Button>
        </Fields>
        <Frame $viewport={viewport}>
          {error && <Alert variant="danger">{error}</Alert>}
          {/* sandbox sans allow-scripts : le HTML de l'e-mail ne peut exécuter aucun script */}
          {data && <iframe title={`Aperçu de l'e-mail ${preview.label}`} sandbox="" srcDoc={data.html} />}
        </Frame>
      </Split>
    </Panel>
  );
};

const Layout = styled.div`
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  gap: ${({ theme }) => theme.spacing[4]};
  align-items: start;

  @media (max-width: 1000px) {
    grid-template-columns: 1fr;
  }
`;

const TemplateList = styled.ul`
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: ${({ theme }) => theme.spacing[1]};
`;

const TemplateButton = styled.button<{ $active: boolean }>`
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: ${({ theme }) => theme.spacing[1]};
  padding: ${({ theme }) => theme.spacing[3]};
  border: 1px solid ${({ theme, $active }) => ($active ? theme.colors.primary.base : 'transparent')};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme, $active }) => ($active ? theme.colors.primary.subtle : 'transparent')};
  color: inherit;
  text-align: left;
  cursor: pointer;

  code {
    font-size: 0.7rem;
    color: ${({ theme }) => theme.colors.text.muted};
  }

  &:hover {
    background: ${({ theme, $active }) => ($active ? theme.colors.primary.subtle : theme.colors.background.surfaceHover)};
  }
`;

const Split = styled.div`
  display: grid;
  grid-template-columns: 300px minmax(0, 1fr);
  gap: ${({ theme }) => theme.spacing[4]};
  margin-top: ${({ theme }) => theme.spacing[4]};

  @media (max-width: 1200px) {
    grid-template-columns: 1fr;
  }
`;

const Fields = styled.div`
  min-width: 0;
`;

const Frame = styled.div<{ $viewport: Viewport }>`
  display: flex;
  justify-content: center;
  padding: ${({ theme }) => theme.spacing[3]};
  border-radius: ${({ theme }) => theme.radii.md};
  background: ${({ theme }) => theme.colors.background.surfaceHover};

  iframe {
    width: ${({ $viewport }) => ($viewport === 'mobile' ? '375px' : '100%')};
    max-width: 100%;
    height: 760px;
    border: 0;
    border-radius: ${({ theme }) => theme.radii.sm};
    background: #fff;
    transition: width 0.2s ease;
  }
`;
