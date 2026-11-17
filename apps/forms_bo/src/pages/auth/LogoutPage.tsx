import { useAuthStore } from '@/stores/auth.store';
import { useEffect } from 'react';
import { Navigate } from 'react-router-dom';

/** Le JWT est sans état : la déconnexion consiste à oublier le jeton côté client. */
export const LogoutPage = () => {
  const logout = useAuthStore((state) => state.logout);

  useEffect(() => {
    logout();
  }, [logout]);

  return <Navigate to="/login" replace />;
};
