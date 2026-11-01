<script setup lang="ts">
import '@/plugins/echarts'
import { computed, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { dashboardApi } from '@/api/dashboard.api'
import { isApiError } from '@/api/http'
import PriorityChart from '@/components/dashboard/PriorityChart.vue'
import StatCard from '@/components/dashboard/StatCard.vue'
import StatusChart from '@/components/dashboard/StatusChart.vue'
import TrendChart from '@/components/dashboard/TrendChart.vue'
import { useDebouncedRefetch } from '@/composables/useDebouncedRefetch'
import { useRealtime } from '@/composables/useRealtime'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { DashboardFilters, DashboardStats, IncidentStatus, IncidentType, Priority } from '@/types/api'
import { formatDateTime, toLocalInput } from '@/utils/dates'

const ACTIVE: IncidentStatus[] = ['open', 'in_progress', 'on_hold']

const router = useRouter()
const catalogs = useCatalogsStore()

// No default range: the dashboard opens on the whole population (S-10).
const filters = reactive<{ from: string | null; to: string | null; type: IncidentType | null }>({
  from: null,
  to: null,
  type: null,
})
const stats = ref<DashboardStats | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const params = computed<DashboardFilters>(() => ({
  date_from: filters.from ?? undefined,
  date_to: filters.to ?? undefined,
  type: filters.type ?? undefined,
}))

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    stats.value = await dashboardApi.stats(params.value)
  } catch (e) {
    error.value = isApiError(e) ? e.message : 'No se pudieron cargar los indicadores.'
  } finally {
    loading.value = false
  }
}

watch(params, load, { immediate: true, deep: true })
useRealtime(useDebouncedRefetch(load, 2000)) // section 8: dashboard refetches 2 s after the last change

// Range shortcuts in Bogota days (S-08).
const today = () => toLocalInput(new Date().toISOString()).slice(0, 10)
const daysAgo = (days: number) =>
  new Date(Date.parse(`${today()}T00:00:00Z`) - days * 86_400_000).toISOString().slice(0, 10)
const shortcuts = [
  { label: '7 días', apply: () => Object.assign(filters, { from: daysAgo(6), to: today() }) },
  { label: '30 días', apply: () => Object.assign(filters, { from: daysAgo(29), to: today() }) },
  { label: 'Este mes', apply: () => Object.assign(filters, { from: `${today().slice(0, 8)}01`, to: today() }) },
]

const range = computed(() =>
  filters.from
    ? filters.from === filters.to
      ? filters.from
      : { from: filters.from, to: filters.to ?? filters.from }
    : null,
)
const rangeLabel = computed(() =>
  filters.from ? (filters.from === filters.to ? filters.from : `${filters.from} → ${filters.to}`) : 'Todo el periodo',
)

function setRange(value: string | { from: string; to: string } | null): void {
  if (!value) Object.assign(filters, { from: null, to: null })
  else if (typeof value === 'string') Object.assign(filters, { from: value, to: value })
  else Object.assign(filters, { from: value.from, to: value.to })
}

/** Every figure opens the listing with the equivalent filters, so the numbers can be checked. */
function listLink(extra: Record<string, string> = {}) {
  const query: Record<string, string> = { ...extra }
  if (filters.from) query.date_from = filters.from
  if (filters.to) query.date_to = filters.to
  if (filters.type) query.type = filters.type
  return { name: 'incidents', query }
}

const overdueShare = computed(() =>
  stats.value && stats.value.active > 0
    ? `${Math.round((stats.value.overdue / stats.value.active) * 100)} % de los activos`
    : 'Sin casos activos',
)

const openStatus = (status: IncidentStatus) => router.push(listLink({ status }))
const openPriority = (priority: Priority, overdueOnly: boolean) =>
  router.push(listLink({ priority, ...(overdueOnly ? { overdue: 'true' } : {}) }))
const openDay = (date: string) =>
  router.push({
    name: 'incidents',
    query: { date_from: date, date_to: date, ...(filters.type ? { type: filters.type } : {}) },
  })
</script>

<template>
  <q-page padding>
    <div class="row items-center q-col-gutter-sm q-mb-md">
      <div class="col-12 col-md">
        <h1 class="page-title">Dashboard</h1>
        <div v-if="stats" class="text-caption text-grey-7">Actualizado {{ formatDateTime(stats.generated_at) }}</div>
      </div>
      <div class="col-12 col-md-auto row items-center q-gutter-sm">
        <q-btn-group flat>
          <q-btn
            v-for="s in shortcuts"
            :key="s.label"
            flat
            dense
            no-caps
            color="primary"
            :label="s.label"
            @click="s.apply"
          />
        </q-btn-group>
        <q-input
          :model-value="rangeLabel"
          dense
          outlined
          readonly
          class="range-input"
          aria-label="Rango de fechas de creación"
        >
          <template #prepend>
            <q-icon name="event" class="cursor-pointer" aria-label="Elegir fechas">
              <q-popup-proxy cover transition-show="scale" transition-hide="scale">
                <q-date :model-value="range" range mask="YYYY-MM-DD" today-btn @update:model-value="setRange">
                  <div class="row justify-end"><q-btn v-close-popup label="Listo" color="primary" flat /></div>
                </q-date>
              </q-popup-proxy>
            </q-icon>
          </template>
          <template v-if="filters.from" #append>
            <q-icon name="close" class="cursor-pointer" aria-label="Quitar rango" @click="setRange(null)" />
          </template>
        </q-input>
        <q-select
          v-model="filters.type"
          :options="catalogs.catalogs?.types ?? []"
          label="Tipo"
          emit-value
          map-options
          dense
          outlined
          clearable
          class="type-select"
        />
        <q-btn flat round dense icon="refresh" aria-label="Actualizar" :loading="loading" @click="load">
          <q-tooltip>Actualizar</q-tooltip>
        </q-btn>
      </div>
    </div>

    <q-banner v-if="error" rounded class="bg-red-1 text-negative q-mb-md" role="alert">
      <template #avatar><q-icon name="error_outline" /></template>
      {{ error }}
      <template #action><q-btn flat color="negative" label="Reintentar" @click="load" /></template>
    </q-banner>

    <template v-if="stats">
      <div class="row q-col-gutter-md q-mb-md">
        <div class="col-6 col-md-3">
          <StatCard label="Total" :value="stats.total" icon="inventory_2" color="primary" :to="listLink()" />
        </div>
        <div class="col-6 col-md-3">
          <StatCard
            label="Activos"
            :value="stats.active"
            icon="pending_actions"
            color="indigo-6"
            :to="listLink({ status: ACTIVE.join(',') })"
          />
        </div>
        <div class="col-6 col-md-3">
          <StatCard
            label="Vencidos"
            :value="stats.overdue"
            icon="schedule"
            color="negative"
            :caption="overdueShare"
            :to="listLink({ overdue: 'true' })"
          />
        </div>
        <div class="col-6 col-md-3">
          <StatCard
            label="Sin asignar"
            :value="stats.unassigned"
            icon="person_off"
            color="amber-9"
            caption="Activos sin responsable"
            :to="listLink({ assigned_to: 'unassigned', status: ACTIVE.join(',') })"
          />
        </div>
      </div>

      <div class="row q-col-gutter-md">
        <div class="col-12 col-md-6">
          <q-card flat bordered>
            <q-card-section class="q-pb-none text-subtitle2">Por estado</q-card-section>
            <q-card-section><StatusChart :data="stats.by_status" @select="openStatus" /></q-card-section>
          </q-card>
        </div>
        <div class="col-12 col-md-6">
          <q-card flat bordered>
            <q-card-section class="q-pb-none text-subtitle2">Por prioridad</q-card-section>
            <q-card-section><PriorityChart :data="stats.by_priority" @select="openPriority" /></q-card-section>
          </q-card>
        </div>
        <div class="col-12">
          <q-card flat bordered>
            <q-card-section class="q-pb-none">
              <div class="text-subtitle2">Creados vs. resueltos</div>
              <div class="text-caption text-grey-7">
                Últimos 30 días (no depende del rango). Haz clic en un día de «Creados» para verlos.
              </div>
            </q-card-section>
            <q-card-section><TrendChart :data="stats.trend" @select="openDay" /></q-card-section>
          </q-card>
        </div>
      </div>
    </template>

    <q-inner-loading :showing="loading && !stats" />
  </q-page>
</template>

<style scoped>
.range-input {
  min-width: 220px;
}

.type-select {
  min-width: 140px;
}
</style>
