<script setup lang="ts">
import { ref, watch } from 'vue'
import { incidentsApi } from '@/api/incidents.api'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { Activity, IncidentStatus, Priority } from '@/types/api'
import { formatDateTime } from '@/utils/dates'

/** reloadKey changes after each command so the newest entries appear. */
const props = defineProps<{ incidentId: number; reloadKey: number }>()
const catalogs = useCatalogsStore()

const items = ref<Activity[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const failed = ref(false)

const FIELD_LABELS: Record<string, string> = { title: 'el título', description: 'la descripción', type: 'el tipo', due_at: 'el vencimiento' }

function describe(activity: Activity): string {
  const change = (field: string) => activity.changes[field] as { from: unknown; to: unknown } | undefined
  switch (activity.action) {
    case 'created':
      return 'registró el caso'
    case 'updated':
      return `cambió ${Object.keys(activity.changes).map((f) => FIELD_LABELS[f] ?? f).join(', ')}`
    case 'reprioritized': {
      const c = change('priority')
      return `cambió la prioridad de ${catalogs.priorityLabel(c?.from as Priority)} a ${catalogs.priorityLabel(c?.to as Priority)}`
    }
    case 'status_changed': {
      const c = change('status')
      return `cambió el estado de ${catalogs.statusLabel(c?.from as IncidentStatus)} a ${catalogs.statusLabel(c?.to as IncidentStatus)}`
    }
    case 'assigned':
      return 'asignó un responsable'
    case 'unassigned':
      return 'quitó el responsable'
    case 'deleted':
      return 'eliminó el caso'
  }
}

const ICONS: Record<Activity['action'], string> = {
  created: 'add_circle',
  updated: 'edit',
  reprioritized: 'low_priority',
  status_changed: 'swap_horiz',
  assigned: 'person_add',
  unassigned: 'person_remove',
  deleted: 'delete',
}

async function load(nextPage: number): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const result = await incidentsApi.activities(props.incidentId, nextPage)
    items.value = nextPage === 1 ? result.data : [...items.value, ...result.data]
    page.value = result.meta.current_page
    lastPage.value = result.meta.last_page
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}

watch(() => [props.incidentId, props.reloadKey], () => load(1), { immediate: true })
</script>

<template>
  <div>
    <q-timeline color="primary" layout="dense" class="q-mt-none">
      <q-timeline-entry
        v-for="activity in items"
        :key="activity.id"
        :icon="ICONS[activity.action]"
        :subtitle="formatDateTime(activity.created_at)"
      >
        <div>
          <span class="text-weight-medium">{{ activity.actor?.name ?? 'Sistema' }}</span> {{ describe(activity) }}
        </div>
        <blockquote v-if="activity.note" class="q-ma-none q-mt-xs q-pa-sm bg-grey-2 rounded-borders">{{ activity.note }}</blockquote>
      </q-timeline-entry>
    </q-timeline>

    <div v-if="failed" class="text-negative q-mb-sm">
      No se pudo cargar la actividad. <q-btn flat dense no-caps color="primary" label="Reintentar" @click="load(page)" />
    </div>
    <q-btn v-if="page < lastPage" flat no-caps color="primary" label="Ver actividad anterior" :loading="loading" @click="load(page + 1)" />
    <q-inner-loading :showing="loading && items.length === 0" />
  </div>
</template>
