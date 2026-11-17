import { Button, EmptyState } from 'core-react/ui';

export const NotFoundPage = () => (
  <EmptyState
    icon="help-circle-outline"
    title="Page introuvable"
    description="Cette page n'existe pas ou vous n'y avez pas accès."
    action={<Button href="/surveys">Retour à mes questionnaires</Button>}
  />
);
