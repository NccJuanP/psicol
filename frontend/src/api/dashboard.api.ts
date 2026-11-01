import { http } from './http'
import type { DashboardFilters, DashboardStats } from '@/types/api'

export const dashboardApi = {
  stats: (params: DashboardFilters) =>
    http.get<{ data: DashboardStats }>('/dashboard/stats', { params }).then((r) => r.data.data),
}
