import { AppLayout } from '@/layouts/AppLayout';
import { EmailPreviewPage } from '@/pages/admin/EmailPreviewPage';
import { LogsPage } from '@/pages/admin/LogsPage';
import { LoginPage } from '@/pages/auth/LoginPage';
import { LogoutPage } from '@/pages/auth/LogoutPage';
import { NotFoundPage } from '@/pages/NotFoundPage';
import { ParticipantPage } from '@/pages/participant/ParticipantPage';
import { SearchPage } from '@/pages/search/SearchPage';
import { DistributionPage } from '@/pages/surveys/distribution/DistributionPage';
import { SurveyBuilderPage } from '@/pages/surveys/builder/SurveyBuilderPage';
import { StatsPage } from '@/pages/surveys/stats/StatsPage';
import { SurveysPage } from '@/pages/surveys/SurveysPage';
import { createBrowserRouter, Navigate } from 'react-router-dom';
import { GuestOnly, RequireAdmin, RequireAuth } from './guards';

export const router = createBrowserRouter([
  // Formulaire répondant : sans compte, hors de la coque du dashboard (specs_fonc §6)
  { path: '/s/:token', element: <ParticipantPage /> },

  { element: <GuestOnly />, children: [{ path: '/login', element: <LoginPage /> }] },
  { path: '/logout', element: <LogoutPage /> },

  {
    element: <RequireAuth />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <Navigate to="/surveys" replace /> },
          { path: '/surveys', element: <SurveysPage /> },
          { path: '/surveys/:id', element: <SurveyBuilderPage /> },
          { path: '/surveys/:id/recipients', element: <DistributionPage /> },
          { path: '/surveys/:id/stats', element: <StatsPage /> },
          { path: '/search', element: <SearchPage /> },
          {
            element: <RequireAdmin />,
            children: [
              { path: '/admin/emails', element: <EmailPreviewPage /> },
              { path: '/admin/logs', element: <LogsPage /> },
            ],
          },
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
]);
