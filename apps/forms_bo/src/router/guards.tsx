import { isAdmin, useAuthStore } from '@/stores/auth.store';
import { PageLoading } from '@/ui/Page';
import { Navigate, Outlet, useLocation } from 'react-router-dom';

/** Routes du dashboard : session obligatoire. */
export const RequireAuth = () => {
  const { token, user, isLoading } = useAuthStore();
  const location = useLocation();

  if (isLoading) return <PageLoading label="Vérification de la session…" />;
  if (!token || !user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;

  return <Outlet />;
};

/** Espace Administration : ROLE_ADMIN obligatoire. */
export const RequireAdmin = () => {
  const user = useAuthStore((state) => state.user);

  return isAdmin(user) ? <Outlet /> : <Navigate to="/surveys" replace />;
};

/** Écran de connexion : redirige si une session est déjà ouverte. */
export const GuestOnly = () => {
  const { token, user, isLoading } = useAuthStore();

  if (isLoading) return <PageLoading label="Vérification de la session…" />;
  if (token && user) return <Navigate to="/surveys" replace />;

  return <Outlet />;
};
