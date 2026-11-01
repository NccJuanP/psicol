// Contract types from docs/api-contract.md, section 7. Change them only together with that file and the API Resources.

export type Priority = 'low' | 'medium' | 'high' | 'critical'
export type IncidentStatus = 'open' | 'in_progress' | 'on_hold' | 'resolved' | 'closed' | 'cancelled'
export type IncidentType = 'incident' | 'request'
export type Role = 'admin' | 'agent' | 'requester'

export interface UserSummary {
  id: number
  name: string
}
export interface AuthUser extends UserSummary {
  email: string
  roles: Role[]
  permissions: string[]
}
export interface LoginResponse {
  token: string
  token_type: 'Bearer'
  expires_at: string
  user: AuthUser
}

export interface IncidentListItem {
  id: number
  code: string // "INC-000123"
  type: IncidentType
  title: string
  priority: Priority
  status: IncidentStatus
  is_overdue: boolean
  due_at: string
  creator: UserSummary
  assignee: UserSummary | null
  created_at: string
  updated_at: string
}

export interface IncidentDetail extends IncidentListItem {
  description: string
  resolved_at: string | null
  closed_at: string | null
  allowed_transitions: IncidentStatus[] // calculado para el usuario actual
  can: { update: boolean; reprioritize: boolean; assign: boolean; delete: boolean }
}

export interface IncidentCreatePayload {
  type?: IncidentType // por defecto 'incident'
  title: string
  description: string
  priority: Priority
  due_at?: string // si falta, lo calcula DueDatePolicy
  assigned_to?: number | null // solo con permiso incidents.assign
}
export type IncidentUpdatePayload = Partial<
  Pick<IncidentCreatePayload, 'type' | 'title' | 'description' | 'priority' | 'due_at'>
>
export interface TransitionPayload {
  status: IncidentStatus
  note?: string
} // note obligatoria al reabrir
export interface AssignPayload {
  assigned_to: number | null
}

export type ActivityAction =
  'created' | 'updated' | 'assigned' | 'unassigned' | 'status_changed' | 'reprioritized' | 'deleted'
export interface Activity {
  id: number
  action: ActivityAction
  changes: Record<string, { from: unknown; to: unknown }>
  note: string | null
  actor: UserSummary | null
  created_at: string
}

export interface CatalogItem<T extends string> {
  value: T
  label: string
}
export interface Catalogs {
  priorities: CatalogItem<Priority>[]
  statuses: (CatalogItem<IncidentStatus> & { is_terminal: boolean })[]
  types: CatalogItem<IncidentType>[]
}

export interface DashboardFilters {
  date_from?: string
  date_to?: string
  type?: IncidentType
}
export interface DashboardStats {
  total: number
  active: number // open + in_progress + on_hold
  overdue: number // activos con due_at vencido
  unassigned: number // activos sin responsable
  by_status: { status: IncidentStatus; count: number }[] // todos los estados, orden del ciclo de vida, con 0
  by_priority: { priority: Priority; count: number; overdue: number }[] // todas las prioridades, con 0
  trend: { date: string; created: number; resolved: number }[] // 30 días (YYYY-MM-DD, America/Bogota), con 0
  generated_at: string
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
    from: number | null
    to: number | null
  }
  links: { first: string; last: string; prev: string | null; next: string | null }
}

export interface ApiError {
  message: string
  code: string
  errors?: Record<string, string[]>
  request_id?: string
}
