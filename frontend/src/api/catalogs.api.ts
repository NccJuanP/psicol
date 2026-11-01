import { http } from './http'
import type { Catalogs } from '@/types/api'

export const catalogsApi = {
  get: () => http.get<{ data: Catalogs }>('/catalogs').then((r) => r.data.data),
}
