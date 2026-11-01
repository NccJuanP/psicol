<script setup lang="ts">
import { computed, ref } from 'vue'
import { usersApi } from '@/api/users.api'
import type { IncidentFilterState } from '@/composables/useIncidentFilters'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { UserSummary } from '@/types/api'

const props = defineProps<{ state: IncidentFilterState; canFilterAssignee: boolean; activeCount: number }>()
const emit = defineEmits<{ update: [patch: Partial<IncidentFilterState>]; reset: [] }>()

const catalogs = useCatalogsStore()
const users = ref<UserSummary[]>([])

const assigneeOptions = computed(() => [
  { label: 'Mis casos', value: 'me' },
  { label: 'Sin asignar', value: 'unassigned' },
  ...users.value.map((user) => ({ label: user.name, value: String(user.id) })),
  // keep a user id coming from the URL selectable even before searching
  ...(props.state.assigned_to &&
  /^\d+$/.test(props.state.assigned_to) &&
  !users.value.some((u) => String(u.id) === props.state.assigned_to)
    ? [{ label: `Usuario #${props.state.assigned_to}`, value: props.state.assigned_to }]
    : []),
])

function searchUsers(search: string, done: (callback: () => void) => void, abort: () => void): void {
  usersApi
    .assignable(search)
    .then((found) => done(() => (users.value = found)))
    .catch(() => abort())
}

const range = computed(() => {
  const { date_from: from, date_to: to } = props.state
  if (!from && !to) return null
  return from === to || !to ? from : { from, to }
})
const rangeLabel = computed(() => {
  const { date_from: from, date_to: to } = props.state
  if (!from && !to) return ''
  return from === to || !to ? (from ?? '') : `${from} → ${to}`
})

function setRange(value: string | { from: string; to: string } | null): void {
  if (!value) emit('update', { date_from: null, date_to: null })
  else if (typeof value === 'string') emit('update', { date_from: value, date_to: value })
  else emit('update', { date_from: value.from, date_to: value.to })
}
</script>

<template>
  <div class="row q-col-gutter-sm items-start">
    <div class="col-12 col-md-4">
      <q-input
        :model-value="state.search"
        label="Buscar por texto o código (INC-123)"
        outlined
        dense
        clearable
        debounce="400"
        @update:model-value="emit('update', { search: String($event ?? '') })"
      >
        <template #prepend><q-icon name="search" /></template>
      </q-input>
    </div>

    <div class="col-12 col-sm-6 col-md-4">
      <q-select
        :model-value="state.status"
        :options="catalogs.catalogs?.statuses ?? []"
        label="Estado"
        multiple
        emit-value
        map-options
        use-chips
        outlined
        dense
        clearable
        @update:model-value="emit('update', { status: $event ?? [] })"
      />
    </div>

    <div class="col-12 col-sm-6 col-md-4">
      <q-select
        :model-value="state.priority"
        :options="catalogs.catalogs?.priorities ?? []"
        label="Prioridad"
        multiple
        emit-value
        map-options
        use-chips
        outlined
        dense
        clearable
        @update:model-value="emit('update', { priority: $event ?? [] })"
      />
    </div>

    <div v-if="canFilterAssignee" class="col-12 col-sm-6 col-md-3">
      <q-select
        :model-value="state.assigned_to"
        :options="assigneeOptions"
        label="Responsable"
        emit-value
        map-options
        use-input
        input-debounce="300"
        outlined
        dense
        clearable
        @filter="searchUsers"
        @update:model-value="emit('update', { assigned_to: $event ?? null })"
      />
    </div>

    <div class="col-12 col-sm-6 col-md-2">
      <q-select
        :model-value="state.type"
        :options="catalogs.catalogs?.types ?? []"
        label="Tipo"
        emit-value
        map-options
        outlined
        dense
        clearable
        @update:model-value="emit('update', { type: $event ?? null })"
      />
    </div>

    <div class="col-12 col-sm-6 col-md-4">
      <q-input
        :model-value="rangeLabel"
        label="Rango de fechas"
        outlined
        dense
        readonly
        clearable
        @clear="setRange(null)"
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
      </q-input>
      <q-btn-toggle
        :model-value="state.date_field"
        :options="[
          { label: 'Creación', value: 'created_at' },
          { label: 'Vencimiento', value: 'due_at' },
        ]"
        size="sm"
        no-caps
        unelevated
        toggle-color="primary"
        class="q-mt-xs"
        aria-label="Fecha a la que aplica el rango"
        @update:model-value="emit('update', { date_field: $event })"
      />
    </div>

    <div class="col-12 col-sm-6 col-md-3 row items-center justify-between no-wrap">
      <q-toggle
        :model-value="state.overdue"
        label="Solo vencidos"
        color="negative"
        @update:model-value="emit('update', { overdue: $event })"
      />
      <q-btn
        v-if="activeCount > 0"
        flat
        dense
        no-caps
        color="primary"
        icon="filter_alt_off"
        label="Limpiar"
        @click="emit('reset')"
      />
    </div>
  </div>
</template>
