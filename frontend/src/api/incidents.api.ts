import { http } from './http'
import type {
  Activity,
  AssignPayload,
  IncidentCreatePayload,
  IncidentDetail,
  IncidentListItem,
  IncidentUpdatePayload,
  Paginated,
  TransitionPayload,
} from '@/types/api'

/** Flat query params, already in the API's shape: { page, sort, search, 'filter[status]': 'open,on_hold', … }. */
export type ListParams = Record<string, string | number | undefined>

export const incidentsApi = {
  list: (params: ListParams) => http.get<Paginated<IncidentListItem>>('/incidents', { params }).then((r) => r.data),
  get: (id: number) => http.get<{ data: IncidentDetail }>(`/incidents/${id}`).then((r) => r.data.data),
  create: (payload: IncidentCreatePayload) =>
    http.post<{ data: IncidentDetail }>('/incidents', payload).then((r) => r.data.data),
  update: (id: number, payload: IncidentUpdatePayload) =>
    http.patch<{ data: IncidentDetail }>(`/incidents/${id}`, payload).then((r) => r.data.data),
  transition: (id: number, payload: TransitionPayload) =>
    http.post<{ data: IncidentDetail }>(`/incidents/${id}/transition`, payload).then((r) => r.data.data),
  assign: (id: number, payload: AssignPayload) =>
    http.post<{ data: IncidentDetail }>(`/incidents/${id}/assign`, payload).then((r) => r.data.data),
  remove: (id: number) => http.delete(`/incidents/${id}`),
  activities: (id: number, page = 1) =>
    http.get<Paginated<Activity>>(`/incidents/${id}/activities`, { params: { page } }).then((r) => r.data),

  /** The download needs the Bearer header, so it is fetched as a Blob instead of opened as a link. */
  async export(format: 'csv' | 'xlsx', params: ListParams): Promise<{ blob: Blob; filename: string }> {
    const response = await http.get<Blob>('/incidents/export', { params: { ...params, format }, responseType: 'blob' })
    const disposition = String(response.headers['content-disposition'] ?? '')
    const filename = /filename="?([^";]+)"?/.exec(disposition)?.[1] ?? `incidentes.${format}`
    return { blob: response.data, filename }
  },
}
