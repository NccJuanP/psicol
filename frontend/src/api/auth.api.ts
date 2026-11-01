import { http } from './http'
import type { AuthUser, LoginResponse } from '@/types/api'

export const authApi = {
  login: (email: string, password: string) =>
    http.post<{ data: LoginResponse }>('/auth/login', { email, password }).then((r) => r.data.data),
  logout: () => http.post('/auth/logout'),
  me: () => http.get<{ data: AuthUser }>('/auth/me').then((r) => r.data.data),
}
