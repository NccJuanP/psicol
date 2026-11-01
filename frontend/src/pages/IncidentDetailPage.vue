<script setup lang="ts">
import { useQuasar } from 'quasar'
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { isApiError } from '@/api/http'
import { incidentsApi } from '@/api/incidents.api'
import EmptyState from '@/components/common/EmptyState.vue'
import ActivityTimeline from '@/components/incidents/ActivityTimeline.vue'
import AssigneeSelect from '@/components/incidents/AssigneeSelect.vue'
import PriorityChip from '@/components/incidents/PriorityChip.vue'
import StatusBadge from '@/components/incidents/StatusBadge.vue'
import TransitionActions from '@/components/incidents/TransitionActions.vue'
import { useDebouncedRefetch } from '@/composables/useDebouncedRefetch'
import { useRealtime } from '@/composables/useRealtime'
import { useAuthStore } from '@/stores/auth.store'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { IncidentDetail, IncidentStatus } from '@/types/api'
import { formatDateTime } from '@/utils/dates'
import { transitionLabel } from '@/utils/presentation'

const DOMAIN_CONFLICTS = ['INVALID_STATUS_TRANSITION', 'ASSIGNEE_REQUIRED', 'INCIDENT_LOCKED']

const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const catalogs = useCatalogsStore()

const id = computed(() => Number(route.params.id))
const incident = ref<IncidentDetail | null>(null)
const notFound = ref(false)
const loading = ref(false)
const busy = ref(false)
const timelineKey = ref(0)
const assignDialog = ref(false)
const assignee = ref<number | null>(null)

const isAdmin = computed(() => auth.user?.roles.includes('admin') ?? false)
const canEdit = computed(() => !!incident.value && (incident.value.can.update || incident.value.can.reprioritize))

async function load(): Promise<void> {
  loading.value = true
  notFound.value = false
  try {
    incident.value = await incidentsApi.get(id.value)
  } catch (e) {
    if (isApiError(e) && e.code === 'NOT_FOUND') notFound.value = true
  } finally {
    loading.value = false
  }
}

watch(id, load, { immediate: true })

// Section 8: someone else changed this case → reload detail and timeline (own changes are already applied).
const reloadSoon = useDebouncedRefetch(async () => {
  await load()
  timelineKey.value++
}, 500)
useRealtime((event) => {
  if (event.id === id.value && event.actor?.id !== auth.user?.id) reloadSoon()
})

/** Runs a command; on a 409 the interceptor already showed the reason, so just reload the truth. */
async function run(command: () => Promise<IncidentDetail>, done: string): Promise<boolean> {
  busy.value = true
  try {
    incident.value = await command()
    timelineKey.value++
    $q.notify({ type: 'positive', message: done })
    return true
  } catch (e) {
    if (isApiError(e) && DOMAIN_CONFLICTS.includes(e.code)) {
      await load()
      timelineKey.value++
    }
    return false
  } finally {
    busy.value = false
  }
}

function onTransition(to: IncidentStatus, note?: string): void {
  const from = incident.value!.status
  void run(() => incidentsApi.transition(id.value, { status: to, note }), `${transitionLabel(from, to)}: listo.`)
}

function openAssign(): void {
  assignee.value = incident.value?.assignee?.id ?? null
  assignDialog.value = true
}

async function saveAssignee(): Promise<void> {
  if (await run(() => incidentsApi.assign(id.value, { assigned_to: assignee.value }), 'Responsable actualizado.')) {
    assignDialog.value = false
  }
}

function takeIncident(): void {
  void run(() => incidentsApi.assign(id.value, { assigned_to: auth.user!.id }), 'Ahora eres el responsable.')
}

function remove(): void {
  $q.dialog({
    title: `Eliminar ${incident.value?.code}`,
    message: 'El caso dejará de aparecer en listados e indicadores. Su historial se conserva.',
    cancel: { label: 'Volver', flat: true },
    ok: { label: 'Eliminar', color: 'negative', unelevated: true },
  }).onOk(async () => {
    busy.value = true
    try {
      await incidentsApi.remove(id.value)
      $q.notify({ type: 'positive', message: `${incident.value?.code} eliminado.` })
      await router.push({ name: 'incidents' })
    } catch {
      /* notified by the interceptor */
    } finally {
      busy.value = false
    }
  })
}
</script>

<template>
  <q-page padding>
    <div class="detail-wrap">
      <div class="row items-center q-mb-md no-wrap">
        <q-btn flat round dense icon="arrow_back" aria-label="Volver al listado" class="q-mr-sm" @click="router.back()" />
        <span class="text-grey-8">Incidentes</span>
      </div>

      <q-inner-loading :showing="loading && !incident" />

      <EmptyState v-if="notFound" icon="search_off" title="Incidente no encontrado" message="No existe, fue eliminado o no tienes acceso a él.">
        <q-btn color="primary" unelevated label="Ir al listado" :to="{ name: 'incidents' }" />
      </EmptyState>

      <template v-else-if="incident">
        <q-card flat bordered class="q-mb-md">
          <q-card-section>
            <div class="row items-start q-col-gutter-sm">
              <div class="col-12 col-md">
                <div class="text-caption text-grey-7">{{ incident.code }} · {{ catalogs.typeLabel(incident.type) }}</div>
                <h1 class="page-title q-mt-xs">{{ incident.title }}</h1>
                <div class="row items-center q-gutter-sm q-mt-sm">
                  <StatusBadge :status="incident.status" />
                  <PriorityChip :priority="incident.priority" />
                </div>
              </div>
              <div class="col-12 col-md-auto row q-gutter-sm justify-end">
                <q-btn v-if="canEdit" outline no-caps color="primary" icon="edit" label="Editar" :to="{ name: 'incident-edit', params: { id } }" />
                <q-btn v-if="incident.can.delete" flat no-caps color="negative" icon="delete_outline" label="Eliminar" :disable="busy" @click="remove" />
              </div>
            </div>
          </q-card-section>

          <q-banner v-if="incident.is_overdue" class="bg-red-1 text-negative" role="alert">
            <template #avatar><q-icon name="schedule" /></template>
            Este caso está vencido desde el {{ formatDateTime(incident.due_at) }}
          </q-banner>

          <q-separator v-if="incident.allowed_transitions.length" />
          <q-card-section v-if="incident.allowed_transitions.length">
            <TransitionActions :incident="incident" :busy="busy" @transition="onTransition" />
          </q-card-section>
        </q-card>

        <div class="row q-col-gutter-md">
          <div class="col-12 col-md-8">
            <q-card flat bordered class="q-mb-md">
              <q-card-section>
                <div class="text-subtitle2 q-mb-sm">Descripción</div>
                <p class="description q-mb-none">{{ incident.description }}</p>
              </q-card-section>
            </q-card>

            <q-card flat bordered>
              <q-card-section>
                <div class="text-subtitle2 q-mb-sm">Actividad</div>
                <ActivityTimeline :incident-id="incident.id" :reload-key="timelineKey" />
              </q-card-section>
            </q-card>
          </div>

          <div class="col-12 col-md-4">
            <q-card flat bordered>
              <q-list separator>
                <q-item>
                  <q-item-section>
                    <q-item-label caption>Responsable</q-item-label>
                    <q-item-label>{{ incident.assignee?.name ?? 'Sin asignar' }}</q-item-label>
                  </q-item-section>
                  <q-item-section v-if="incident.can.assign" side>
                    <q-btn v-if="isAdmin" flat dense no-caps color="primary" label="Cambiar" :disable="busy" @click="openAssign" />
                    <q-btn v-else flat dense no-caps color="primary" label="Tomar caso" :disable="busy" @click="takeIncident" />
                  </q-item-section>
                </q-item>
                <q-item>
                  <q-item-section>
                    <q-item-label caption>Solicitante</q-item-label>
                    <q-item-label>{{ incident.creator.name }}</q-item-label>
                  </q-item-section>
                </q-item>
                <q-item>
                  <q-item-section>
                    <q-item-label caption>Vence</q-item-label>
                    <q-item-label :class="{ 'text-negative text-weight-medium': incident.is_overdue }">{{ formatDateTime(incident.due_at) }}</q-item-label>
                  </q-item-section>
                </q-item>
                <q-item>
                  <q-item-section>
                    <q-item-label caption>Creado</q-item-label>
                    <q-item-label>{{ formatDateTime(incident.created_at) }}</q-item-label>
                  </q-item-section>
                </q-item>
                <q-item>
                  <q-item-section>
                    <q-item-label caption>Última actualización</q-item-label>
                    <q-item-label>{{ formatDateTime(incident.updated_at) }}</q-item-label>
                  </q-item-section>
                </q-item>
                <q-item v-if="incident.resolved_at">
                  <q-item-section>
                    <q-item-label caption>Resuelto</q-item-label>
                    <q-item-label>{{ formatDateTime(incident.resolved_at) }}</q-item-label>
                  </q-item-section>
                </q-item>
                <q-item v-if="incident.closed_at">
                  <q-item-section>
                    <q-item-label caption>Cerrado</q-item-label>
                    <q-item-label>{{ formatDateTime(incident.closed_at) }}</q-item-label>
                  </q-item-section>
                </q-item>
              </q-list>
            </q-card>
          </div>
        </div>
      </template>
    </div>

    <q-dialog v-model="assignDialog">
      <q-card style="width: 420px; max-width: 92vw">
        <q-card-section class="text-h6">Asignar responsable</q-card-section>
        <q-card-section>
          <AssigneeSelect v-model="assignee" :initial="incident?.assignee" />
          <div class="text-caption text-grey-7 q-mt-sm">Deja el campo vacío para quitar el responsable.</div>
        </q-card-section>
        <q-card-actions align="right">
          <q-btn v-close-popup flat no-caps label="Cancelar" />
          <q-btn unelevated no-caps color="primary" label="Guardar" :loading="busy" @click="saveAssignee" />
        </q-card-actions>
      </q-card>
    </q-dialog>
  </q-page>
</template>

<style scoped>
.detail-wrap {
  max-width: 1100px;
  margin: 0 auto;
}

.description {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
</style>
