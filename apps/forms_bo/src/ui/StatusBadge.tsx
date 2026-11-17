import { RECIPIENT_STATUS_BADGES, RECIPIENT_STATUS_LABELS, STATUS_BADGES, STATUS_LABELS, type RecipientStatus, type SurveyStatus } from '@/types';
import { Badge } from 'core-react/ui';

export const SurveyStatusBadge = ({ status }: { status: SurveyStatus }) => (
  <Badge $variant={STATUS_BADGES[status]} $pill>
    {STATUS_LABELS[status]}
  </Badge>
);

export const RecipientStatusBadge = ({ status }: { status: RecipientStatus }) => (
  <Badge $variant={RECIPIENT_STATUS_BADGES[status]} $pill>
    {RECIPIENT_STATUS_LABELS[status]}
  </Badge>
);
