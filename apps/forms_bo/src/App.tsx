import { router } from '@/router';
import { useAuthStore } from '@/stores/auth.store';
import { appTheme, cssVariables } from '@/ui/theme';
import { UIProvider } from 'core-react';
import { initThemeStore } from 'core-react/theme';
import { GlobalStyle, ToastContainer } from 'core-react/ui';
import { useEffect } from 'react';
import { Link as RouterLink, RouterProvider } from 'react-router-dom';
import { ThemeProvider } from 'styled-components';

// SPA : préférence de thème lue avant le premier rendu (aucune hydratation à attendre)
initThemeStore();

// Navigation du kit (BackButton…) : le routeur de données est utilisable hors de RouterProvider
const navigation = {
  back: () => void router.navigate(-1),
  push: (url: string) => void router.navigate(url),
};

export const App = () => {
  const restore = useAuthStore((state) => state.restore);

  useEffect(() => {
    void restore();
  }, [restore]);

  return (
    <ThemeProvider theme={appTheme}>
      <GlobalStyle $cssVariables={cssVariables} />
      <UIProvider linkComponent={RouterLink} linkHrefProp="to" navigation={navigation}>
        <RouterProvider router={router} />
        <ToastContainer />
      </UIProvider>
    </ThemeProvider>
  );
};
