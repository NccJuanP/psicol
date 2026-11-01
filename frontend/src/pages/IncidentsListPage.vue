<script setup lang="ts">
import { type QTableColumn, type QTableProps, useQuasar } from 'quasar'
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { isApiError } from '@/api/http'
import { incidentsApi } from '@/api/incidents.api'
import EmptyState from '@/components/common/EmptyState.vue'
import IncidentFilters from '@/components/incidents/IncidentFilters.vue'
import PriorityChip from '@/components/incidents/PriorityChip.vue'
import StatusBadge from '@/components/incidents/StatusBadge.vue'
import { useIncidentFilters } from '@/composables/useIncidentFilters'
import { useAuthStore } from '@/stores/auth.store'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { IncidentListItem } from '@/types/api'
import { formatDateTime } from '@/utils/dates'

const $q = useQuasar()
const router = useRouter()
const auth = useAuthStore()
const catalogs = useCatalogsStore()
const { state, apiParams, exportParams, activeFilterCount, update, reset } = useIncidentFilters()

const rows = ref<IncidentListItem[]>([])
const total = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)
const exporting = ref<'csv' | 'xlsx' | null>(null)
const showFilters = ref($q.screen.gt.sm)

// Column names are the API sort keys ("id" sorts the code).
const columns: QTableColumn<IncidentListItem>[] = [
  { name: 'id', label: 'Código', field: 'code', sortable: true, align: 'left' },
  { name: 'title', label: 'Título', field: 'title', sortable: true, align: 'left', classes: 'title-cell' },
  { name: 'type', label: 'Tipo', field: (row) => catalogs.typeLabel(row.type), align: 'left' },
  { name: 'priority', label: 'Prioridad', field: 'priority', sortable: true, align: 'left' },
  { name: 'status', label: 'Estado', field: 'status', sortable: true, align: 'left' },
  { name: 'assignee', label: 'Responsable', field: (row) => row.assignee?.name ?? 'Sin asignar', align: 'left' },
  { name: 'due_at', label: 'Vence', field: 'due_at', sortable: true, align: 'left', format: formatDateTime },
  { name: 'created_at', label: 'Creado', field: 'created_at', sortable: true, align: 'left', format: formatDateTime },
]

const pagination = computed(() => ({
  page: state.value.page,
  rowsPerPage: state.value.per_page,
  rowsNumber: total.value,
  sortBy: state.value.sort.replace(/^-/, ''),
  descending: state.value.sort.startsWith('-'),
}))

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const result = await incidentsApi.list(apiParams.value)
    rows.value = result.data
    total.value = result.meta.total
  } catch (e) {
    rows.value = []
    total.value = 0
    error.value = isApiError(e) ? e.message : 'No se pudo cargar el listado.'
  } finally {
    loading.value = false
  }
}

watch(apiParams, load, { immediate: true, deep: true })

const onRequest: QTableProps['onRequest'] = ({ pagination: next }) => {
  const sortBy = next.sortBy || 'created_at'
  void update({ page: next.page, per_page: next.rowsPerPage, sort: `${next.descending ? '-' : ''}${sortBy}` })
}

async function exportAs(format: 'csv' | 'xlsx'): Promise<void> {
  exporting.value = format
  try {
    const { blob, filename } = await incidentsApi.export(format, exportParams.value)
    const url = URL.createObjectURL(blob)
    const link = Object.assign(document.createElement('a'), { href: url, download: filename })
    link.click()
    URL.revokeObjectURL(url)
  } catch {
    $q.notify({ type: 'negative', message: 'No se pudo generar la exportación.' })
  } finally {
    exporting.value = null
  }
}

const openDetail = (_event: Event, row: IncidentListItem) => router.push({ name: 'incident-detail', params: { id: row.id } })
</script>

<template>
  <q-page padding>
    <div class="row items-center q-col-gutter-sm q-mb-md">
      <div class="col">
        <h1 class="page-title">Incidentes</h1>
        <div class="text-caption text-grey-7">{{ total.toLocaleString('es-CO') }} resultados</div>
      </div>
      <div class="col-auto row q-gutter-sm">
        <q-btn
          flat
          no-caps
          color="primary"
          :icon="showFilters ? 'filter_list_off' : 'filter_list'"
          :label="activeFilterCount ? `Filtros (${activeFilterCount})` : 'Filtros'"
          class="lt-md"
          @click="showFilters = !showFilters"
        />
        <q-btn-dropdown
          v-if="auth.can('incidents.export')"
          outline
          no-caps
          color="primary"
          icon="download"
          label="Exportar"
          :loading="exporting !== null"
        >
          <q-list>
            <q-item v-close-popup clickable @click="exportAs('csv')"><q-item-section>CSV</q-item-section></q-item>
            <q-item v-close-popup clickable @click="exportAs('xlsx')"><q-item-section>Excel (XLSX)</q-item-section></q-item>
          </q-list>
        </q-btn-dropdown>
        <q-btn
          v-if="auth.can('incidents.create')"
          unelevated
          no-caps
          color="primary"
          icon="add"
          label="Nuevo"
          :to="{ name: 'incident-create' }"
        />
      </div>
    </div>

    <q-slide-transition>
      <q-card v-show="showFilters || $q.screen.gt.sm" flat bordered class="q-pa-sm q-mb-md">
        <IncidentFilters
          :state="state"
          :can-filter-assignee="auth.can('incidents.assign')"
          :active-count="activeFilterCount"
          @update="update"
          @reset="reset"
        />
      </q-card>
    </q-slide-transition>

    <q-banner v-if="error" rounded class="bg-red-1 text-negative q-mb-md" role="alert">
      <template #avatar><q-icon name="error_outline" /></template>
      {{ error }}
      <template #action><q-btn flat color="negative" label="Reintentar" @click="load" /></template>
    </q-banner>

    <q-table
      flat
      bordered
      row-key="id"
      :rows="rows"
      :columns="columns"
      :pagination="pagination"
      :rows-per-page-options="[15, 25, 50, 100]"
      :loading="loading"
      :grid="$q.screen.lt.md"
      binary-state-sort
      class="incidents-table"
      rows-per-page-label="Filas por página"
      @request="onRequest"
      @row-click="openDetail"
    >
      <template #body-cell-id="props">
        <q-td :props="props" class="text-weight-medium text-primary">{{ props.value }}</q-td>
      </template>
      <template #body-cell-priority="props">
        <q-td :props="props"><PriorityChip :priority="props.row.priority" /></q-td>
      </template>
      <template #body-cell-status="props">
        <q-td :props="props"><StatusBadge :status="props.row.status" /></q-td>
      </template>
      <template #body-cell-due_at="props">
        <q-td :props="props" :class="{ 'text-negative text-weight-medium': props.row.is_overdue }">
          <q-icon v-if="props.row.is_overdue" name="schedule" class="q-mr-xs" aria-label="Vencido" />{{ props.value }}
        </q-td>
      </template>

      <template #item="props">
        <div class="col-12 q-pa-xs">
          <q-card flat bordered class="cursor-pointer" @click="openDetail($event, props.row)">
            <q-card-section class="q-pb-xs">
              <div class="row items-center justify-between no-wrap">
                <span class="text-weight-medium text-primary">{{ props.row.code }}</span>
                <StatusBadge :status="props.row.status" />
              </div>
              <div class="text-body1 q-mt-xs">{{ props.row.title }}</div>
            </q-card-section>
            <q-card-section class="q-pt-xs text-caption text-grey-8 row q-gutter-x-md">
              <PriorityChip :priority="props.row.priority" />
              <span>{{ props.row.assignee?.name ?? 'Sin asignar' }}</span>
              <span :class="{ 'text-negative text-weight-medium': props.row.is_overdue }">
                Vence {{ formatDateTime(props.row.due_at) }}
              </span>
            </q-card-section>
          </q-card>
        </div>
      </template>

      <template #no-data>
        <EmptyState
          v-if="!loading && !error"
          class="full-width"
          icon="inbox"
          title="No hay incidentes"
          :message="activeFilterCount ? 'Ningún caso coincide con los filtros.' : 'Todavía no hay casos registrados.'"
        >
          <q-btn v-if="activeFilterCount" flat color="primary" label="Limpiar filtros" @click="reset" />
        </EmptyState>
      </template>
    </q-table>
  </q-page>
</template>

<style scoped>
.incidents-table :deep(tbody tr) {
  cursor: pointer;
}

.incidents-table :deep(.title-cell) {
  max-width: 360px;
  white-space: normal;
}
</style>
