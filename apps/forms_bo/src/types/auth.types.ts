import type { IsoDate, Uuid } from './survey.types';

export type UserRole = 'ROLE_USER' | 'ROLE_ADMIN';

export interface User {
  id: Uuid;
  email: string;
  displayName: string;
  roles: UserRole[];
  createdAt: IsoDate;
}

export interface AuthResponse {
  token: string;
  user: User;
}
