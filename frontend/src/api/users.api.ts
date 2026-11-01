import { http } from './http'
import type { UserSummary } from '@/types/api'

export const usersApi = {
  assignable: (search?: string) =>
    http.get<{ data: UserSummary[] }>('/users/assignable', { params: { search: search || undefined } }).then((r) => r.data.data),
}
