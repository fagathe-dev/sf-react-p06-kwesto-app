import { isAdmin, useAuthStore } from '@/stores/auth.store';
import { Icon } from 'core-react/icons';
import {
  Sidebar,
  SidebarFooter,
  SidebarHeader,
  SidebarItem,
  SidebarLink,
  SidebarNav,
  SidebarProvider,
  SidebarSection,
  SidebarToggle,
  ThemeModeSwitcher,
} from 'core-react/ui';
import { useState, type FormEvent } from 'react';
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { styled } from 'styled-components';

interface NavItem {
  label: string;
  to: string;
  icon: string;
  exact?: boolean;
}

const MAIN_NAV: NavItem[] = [
  { label: 'Mes questionnaires', to: '/surveys', icon: 'file-document-multiple-outline' },
  { label: 'Recherche', to: '/search', icon: 'magnify' },
];

const ADMIN_NAV: NavItem[] = [
  { label: 'Templates d\'e-mails', to: '/admin/emails', icon: 'email-search-outline' },
  { label: 'Journaux', to: '/admin/logs', icon: 'text-box-search-outline' },
];

/**
 * Coque du dashboard : barre latérale, recherche globale, mode d'affichage.
 * La section Administration n'est visible qu'avec ROLE_ADMIN (specs_fonc §10).
 */
export const AppLayout = () => {
  const user = useAuthStore((state) => state.user);
  const location = useLocation();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');

  const isActive = (item: NavItem) =>
    item.exact ? location.pathname === item.to : location.pathname === item.to || location.pathname.startsWith(`${item.to}/`);

  const onSearch = (event: FormEvent) => {
    event.preventDefault();
    if (query.trim().length >= 2) navigate(`/search?q=${encodeURIComponent(query.trim())}`);
  };

  const renderItem = (item: NavItem) => (
    <SidebarItem key={item.to}>
      <SidebarLink as={Link} to={item.to} active={isActive(item)} icon={<Icon name={item.icon} size={18} />}>
        {item.label}
      </SidebarLink>
    </SidebarItem>
  );

  return (
    <SidebarProvider>
      <Wrapper>
        <Sidebar>
          <SidebarHeader>
            <Brand to="/surveys">
              <Icon name="list-checks" size={24} />
              kwesto
            </Brand>
          </SidebarHeader>
          <SidebarNav>
            <SidebarSection>Questionnaires</SidebarSection>
            {MAIN_NAV.map(renderItem)}
            {isAdmin(user) && (
              <>
                <SidebarSection>Administration</SidebarSection>
                {ADMIN_NAV.map(renderItem)}
              </>
            )}
          </SidebarNav>
          <SidebarFooter>
            <UserBox>
              <strong>{user?.displayName}</strong>
              <span>{user?.email}</span>
            </UserBox>
            <SidebarLink as={Link} to="/logout" icon={<Icon name="logout" size={18} />}>
              Déconnexion
            </SidebarLink>
          </SidebarFooter>
        </Sidebar>

        <Main>
          <TopBar>
            <SidebarToggle aria-label="Ouvrir ou fermer le menu" />
            <SearchForm role="search" onSubmit={onSearch}>
              <Icon name="magnify" size={18} aria-hidden="true" />
              <input
                type="search"
                aria-label="Rechercher dans mes questionnaires"
                placeholder="Rechercher un questionnaire, une question, un destinataire, une réponse…"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
              />
            </SearchForm>
            <ThemeModeSwitcher size="sm" />
          </TopBar>
          <Content>
            <Outlet />
          </Content>
        </Main>
      </Wrapper>
    </SidebarProvider>
  );
};

const Wrapper = styled.div`
  display: flex;
  min-height: 100vh;
  background: ${({ theme }) => theme.colors.background.body};
`;

const Brand = styled(Link)`
  display: inline-flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  font-size: 1.25rem;
  font-weight: 700;
  color: ${({ theme }) => theme.colors.primary.base};
  text-decoration: none;
`;

const UserBox = styled.div`
  display: flex;
  flex-direction: column;
  padding: ${({ theme }) => `${theme.spacing[2]} ${theme.spacing[3]}`};
  font-size: 0.85rem;
  overflow: hidden;

  span {
    color: ${({ theme }) => theme.colors.text.muted};
    text-overflow: ellipsis;
    overflow: hidden;
  }
`;

const Main = styled.div`
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
`;

const TopBar = styled.header`
  position: sticky;
  top: 0;
  z-index: 20;
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[3]};
  height: 64px;
  padding: 0 ${({ theme }) => theme.spacing[5]};
  background: ${({ theme }) => theme.colors.background.surface};
  border-bottom: 1px solid ${({ theme }) => theme.colors.border.default};
`;

const SearchForm = styled.form`
  flex: 1;
  max-width: 560px;
  display: flex;
  align-items: center;
  gap: ${({ theme }) => theme.spacing[2]};
  padding: 0 ${({ theme }) => theme.spacing[3]};
  border: 1px solid ${({ theme }) => theme.colors.border.default};
  border-radius: ${({ theme }) => theme.radii.pill};
  background: ${({ theme }) => theme.colors.background.body};
  color: ${({ theme }) => theme.colors.text.muted};

  &:focus-within {
    border-color: ${({ theme }) => theme.colors.primary.base};
  }

  input {
    flex: 1;
    min-width: 0;
    padding: ${({ theme }) => theme.spacing[2]} 0;
    border: 0;
    outline: none;
    background: transparent;
    color: ${({ theme }) => theme.colors.text.body};
    font: inherit;
  }
`;

const Content = styled.main`
  flex: 1;
  width: 100%;
  max-width: 1200px;
  margin: 0 auto;
  padding: ${({ theme }) => theme.spacing[6]} ${({ theme }) => theme.spacing[5]};
`;
