import { login } from '@/services/api';
import { errorMessage, statusOf } from '@/services/errors';
import { useAuthStore } from '@/stores/auth.store';
import { zodResolver } from '@hookform/resolvers/zod';
import { Icon } from 'core-react/icons';
import { Alert, Button, Card, CardBody, TextField, ThemeModeSwitcher } from 'core-react/ui';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useLocation, useNavigate } from 'react-router-dom';
import { styled } from 'styled-components';
import { z } from 'zod';

const loginSchema = z.object({
  email: z.email('Adresse e-mail invalide.'),
  password: z.string().min(1, 'Le mot de passe est obligatoire.'),
});

type LoginFormData = z.infer<typeof loginSchema>;

interface LoginLocationState {
  from?: string;
}

export const LoginPage = () => {
  const setSession = useAuthStore((state) => state.setSession);
  const navigate = useNavigate();
  const location = useLocation();
  const [apiError, setApiError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormData>({ resolver: zodResolver(loginSchema), defaultValues: { email: '', password: '' } });

  const onSubmit = async ({ email, password }: LoginFormData) => {
    setApiError(null);
    try {
      const { token, user } = await login(email, password);
      setSession(token, user);
      const from = (location.state as LoginLocationState | null)?.from;
      navigate(from && from.startsWith('/') && !from.startsWith('//') ? from : '/surveys', { replace: true });
    } catch (error) {
      setApiError(statusOf(error) === 401 ? 'Adresse e-mail ou mot de passe incorrect.' : errorMessage(error, 'Connexion impossible.'));
    }
  };

  return (
    <Screen>
      <Corner>
        <ThemeModeSwitcher size="sm" />
      </Corner>
      <Box>
        <Brand>
          <Icon name="list-checks" size={36} />
          <span>kwesto</span>
        </Brand>
        <Card $elevation="sm">
          <CardBody>
            <h1>Connexion</h1>
            <form onSubmit={handleSubmit(onSubmit)} noValidate>
              {apiError && (
                <Spaced>
                  <Alert variant="danger">{apiError}</Alert>
                </Spaced>
              )}
              <TextField label="Adresse e-mail" type="email" autoComplete="username" error={errors.email?.message} {...register('email')} />
              <TextField
                label="Mot de passe"
                type="password"
                autoComplete="current-password"
                error={errors.password?.message}
                {...register('password')}
              />
              <Button type="submit" $loading={isSubmitting} $fullWidth>
                Se connecter
              </Button>
            </form>
          </CardBody>
        </Card>
      </Box>
    </Screen>
  );
};

const Screen = styled.div`
  position: relative;
  display: grid;
  min-height: 100vh;
  place-items: center;
  padding: ${({ theme }) => theme.spacing[4]};
  background: ${({ theme }) => theme.colors.background.body};

  h1 {
    margin: 0 0 ${({ theme }) => theme.spacing[4]};
    font-size: 1.4rem;
  }
`;

const Corner = styled.div`
  position: absolute;
  top: ${({ theme }) => theme.spacing[4]};
  right: ${({ theme }) => theme.spacing[4]};
`;

const Box = styled.div`
  width: 100%;
  max-width: 420px;
`;

const Brand = styled.div`
  display: flex;
  align-items: center;
  justify-content: center;
  gap: ${({ theme }) => theme.spacing[2]};
  margin-bottom: ${({ theme }) => theme.spacing[5]};
  font-size: 1.8rem;
  font-weight: 700;
  color: ${({ theme }) => theme.colors.primary.base};
`;

const Spaced = styled.div`
  margin-bottom: ${({ theme }) => theme.spacing[4]};
`;
