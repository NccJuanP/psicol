import { computed } from 'vue'
import { type LocationQuery, useRoute, useRouter } from 'vue-router'
import type { ListParams } from '@/api/incidents.api'
import type { IncidentStatus, IncidentType, Priority } from '@/types/api'

export interface IncidentFilterState {
  search: string
  status: IncidentStatus[]
  priority: Priority[]
  /** User id, "me" or "unassigned". */
  assigned_to: string | null
  type: IncidentType | null
  overdue: boolean
  date_field: 'created_at' | 'due_at'
  /** YYYY-MM-DD, Bogota days. */
  date_from: string | null
  date_to: string | null
}

export interface ListState extends IncidentFilterState {
  page: number
  per_page: number
  /** API sort: "-created_at", "priority", … */
  sort: string
}

const DEFAULTS: ListState = {
  search: '',
  status: [],
  priority: [],
  assigned_to: null,
  type: null,
  overdue: false,
  date_field: 'created_at',
  date_from: null,
  date_to: null,
  page: 1,
  per_page: 15,
  sort: '-created_at',
}

const text = (query: LocationQuery, key: string): string | null => {
  const value = query[key]
  return typeof value === 'string' && value !== '' ? value : null
}
const csv = <T extends string>(query: LocationQuery, key: string) =>
  (text(query, key)?.split(',').filter(Boolean) ?? []) as T[]

function fromQuery(query: LocationQuery): ListState {
  return {
    search: text(query, 'search') ?? '',
    status: csv<IncidentStatus>(query, 'status'),
    priority: csv<Priority>(query, 'priority'),
    assigned_to: text(query, 'assigned_to'),
    type: text(query, 'type') as IncidentType | null,
    overdue: text(query, 'overdue') === 'true',
    date_field: text(query, 'date_field') === 'due_at' ? 'due_at' : 'created_at',
    date_from: text(query, 'date_from'),
    date_to: text(query, 'date_to'),
    page: Number(text(query, 'page')) || DEFAULTS.page,
    per_page: Number(text(query, 'per_page')) || DEFAULTS.per_page,
    sort: text(query, 'sort') ?? DEFAULTS.sort,
  }
}

/** Only non-default values, so URLs stay short and shareable. */
function toQuery(state: ListState): Record<string, string> {
  const query: Record<string, string> = {}
  for (const [key, value] of Object.entries(state) as [keyof ListState, ListState[keyof ListState]][]) {
    const normalized = Array.isArray(value) ? value.join(',') : String(value ?? '')
    const fallback = DEFAULTS[key]
    if (normalized !== '' && normalized !== (Array.isArray(fallback) ? fallback.join(',') : String(fallback ?? ''))) {
      query[key] = normalized
    }
  }
  return query
}

/** API filter params shared by the listing and the export (docs/api-contract.md). */
function filterParams(state: IncidentFilterState): ListParams {
  const ranged = state.date_from !== null || state.date_to !== null
  return {
    search: state.search.trim().length >= 2 ? state.search.trim() : undefined, // the API asks for 2+ characters
    'filter[status]': state.status.join(',') || undefined,
    'filter[priority]': state.priority.join(',') || undefined,
    'filter[assigned_to]': state.assigned_to ?? undefined,
    'filter[type]': state.type ?? undefined,
    'filter[overdue]': state.overdue ? 'true' : undefined,
    'filter[date_field]': ranged && state.date_field !== 'created_at' ? state.date_field : undefined,
    'filter[date_from]': state.date_from ?? undefined,
    'filter[date_to]': state.date_to ?? undefined,
  }
}

/** Listing state lives in the URL query, so reloading, sharing a link or going back keeps the filters. */
export function useIncidentFilters() {
  const route = useRoute()
  const router = useRouter()

  const state = computed(() => fromQuery(route.query))
  const exportParams = computed(() => filterParams(state.value))
  const apiParams = computed<ListParams>(() => ({
    ...exportParams.value,
    page: state.value.page,
    per_page: state.value.per_page,
    sort: state.value.sort,
  }))
  const activeFilterCount = computed(
    () => Object.values(exportParams.value).filter((value) => value !== undefined).length,
  )

  /** Changing any filter goes back to page 1; paging and sorting keep the filters. */
  function update(patch: Partial<ListState>): Promise<unknown> {
    const resetsPage = Object.keys(patch).some((key) => !['page', 'per_page', 'sort'].includes(key))
    return router.replace({ query: toQuery({ ...state.value, ...(resetsPage && { page: 1 }), ...patch }) })
  }

  function reset(): Promise<unknown> {
    return router.replace({ query: toQuery({ ...DEFAULTS, per_page: state.value.per_page, sort: state.value.sort }) })
  }

  return { state, apiParams, exportParams, activeFilterCount, update, reset }
}
