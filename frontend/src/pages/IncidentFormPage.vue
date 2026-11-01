<script setup lang="ts">
import { useQuasar } from 'quasar'
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { isApiError } from '@/api/http'
import { incidentsApi } from '@/api/incidents.api'
import EmptyState from '@/components/common/EmptyState.vue'
import AssigneeSelect from '@/components/incidents/AssigneeSelect.vue'
import { useServerErrors } from '@/composables/useServerErrors'
import { useAuthStore } from '@/stores/auth.store'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { IncidentCreatePayload, IncidentDetail, IncidentType, IncidentUpdatePayload, Priority } from '@/types/api'
import { fromLocalInput, toLocalInput } from '@/utils/dates'

const $q = useQuasar()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const catalogs = useCatalogsStore()
const { errors, setFromError, clearField } = useServerErrors()

const isEdit = computed(() => route.name === 'incident-edit')
const incident = ref<IncidentDetail | null>(null)
const loading = ref(false)
const loadError = ref<'not_found' | 'locked' | null>(null)
const saving = ref(false)

const form = reactive({
  type: 'incident' as IncidentType,
  title: '',
  description: '',
  priority: 'medium' as Priority,
  due_at: '', // datetime-local, Bogota time
  assigned_to: null as number | null,
})
const saved = ref(JSON.stringify(form))
const dirty = computed(() => JSON.stringify(form) !== saved.value)

// What the backend lets this user change (D-24); on create everything the payload accepts.
const canEditDetails = computed(() => !isEdit.value || !!incident.value?.can.update)
const canEditPriority = computed(() => !isEdit.value || !!incident.value?.can.reprioritize)
const canAssign = computed(() => !isEdit.value && auth.can('incidents.assign'))
const isAdmin = computed(() => auth.user?.roles.includes('admin') ?? false)

// Same limits as StoreIncidentRequest / UpdateIncidentRequest (R-03, R-06).
const rules = {
  title: [(v: string) => v.trim().length >= 5 || 'Mínimo 5 caracteres.', (v: string) => v.length <= 150 || 'Máximo 150 caracteres.'],
  description: [
    (v: string) => v.trim().length >= 10 || 'Mínimo 10 caracteres.',
    (v: string) => v.length <= 5000 || 'Máximo 5.000 caracteres.',
  ],
  due_at: [(v: string) => !v || new Date(fromLocalInput(v)) > new Date() || 'Debe ser una fecha futura.'],
}

onMounted(async () => {
  if (!isEdit.value) return
  loading.value = true
  try {
    const data = await incidentsApi.get(Number(route.params.id))
    incident.value = data
    if (!data.can.update && !data.can.reprioritize) loadError.value = 'locked'
    Object.assign(form, {
      type: data.type,
      title: data.title,
      description: data.description,
      priority: data.priority,
      due_at: toLocalInput(data.due_at),
    })
    saved.value = JSON.stringify(form)
  } catch (e) {
    if (isApiError(e) && e.code === 'NOT_FOUND') loadError.value = 'not_found'
  } finally {
    loading.value = false
  }
})

function createPayload(): IncidentCreatePayload {
  return {
    type: form.type,
    title: form.title.trim(),
    description: form.description.trim(),
    priority: form.priority,
    ...(form.due_at && { due_at: fromLocalInput(form.due_at) }),
    ...(form.assigned_to !== null && { assigned_to: form.assigned_to }),
  }
}

/** Only changed fields: sending an untouched priority would need the reprioritize permission. */
function updatePayload(original: IncidentDetail): IncidentUpdatePayload {
  const payload: IncidentUpdatePayload = {}
  if (canEditDetails.value) {
    if (form.type !== original.type) payload.type = form.type
    if (form.title.trim() !== original.title) payload.title = form.title.trim()
    if (form.description.trim() !== original.description) payload.description = form.description.trim()
  }
  if (canEditPriority.value) {
    if (form.priority !== original.priority) payload.priority = form.priority
    if (form.due_at && form.due_at !== toLocalInput(original.due_at)) payload.due_at = fromLocalInput(form.due_at)
  }
  return payload
}

async function submit(): Promise<void> {
  saving.value = true
  try {
    const result =
      isEdit.value && incident.value
        ? await incidentsApi.update(incident.value.id, updatePayload(incident.value))
        : await incidentsApi.create(createPayload())
    saved.value = JSON.stringify(form) // nothing left to lose
    $q.notify({ type: 'positive', message: isEdit.value ? 'Cambios guardados.' : `Se registró ${result.code}.` })
    await router.push({ name: 'incident-detail', params: { id: result.id } })
  } catch (e) {
    setFromError(e)
  } finally {
    saving.value = false
  }
}

function confirmDiscard(): Promise<boolean> {
  return new Promise((resolve) => {
    $q.dialog({
      title: 'Cambios sin guardar',
      message: 'Si sales ahora se perderán los cambios.',
      cancel: { label: 'Seguir editando', flat: true },
      ok: { label: 'Salir sin guardar', color: 'negative', flat: true },
      persistent: true,
    })
      .onOk(() => resolve(true))
      .onCancel(() => resolve(false))
  })
}

onBeforeRouteLeave(() => (dirty.value ? confirmDiscard() : true))

const warnOnUnload = (event: BeforeUnloadEvent) => {
  if (dirty.value) event.preventDefault()
}
window.addEventListener('beforeunload', warnOnUnload)
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnOnUnload))

const backTo = computed(() =>
  isEdit.value ? { name: 'incident-detail', params: { id: route.params.id } } : { name: 'incidents' },
)
</script>

<template>
  <q-page padding>
    <div class="form-wrap">
      <div class="row items-center q-mb-md no-wrap">
        <q-btn flat round dense icon="arrow_back" aria-label="Volver" :to="backTo" class="q-mr-sm" />
        <h1 class="page-title">{{ isEdit ? `Editar ${incident?.code ?? ''}` : 'Nuevo incidente' }}</h1>
      </div>

      <q-inner-loading :showing="loading" />

      <EmptyState v-if="loadError === 'not_found'" icon="search_off" title="Incidente no encontrado" message="No existe o no tienes acceso a él.">
        <q-btn color="primary" unelevated label="Ir al listado" :to="{ name: 'incidents' }" />
      </EmptyState>
      <EmptyState v-else-if="loadError === 'locked'" icon="lock" title="No puedes editar este caso" message="Su estado actual o tu rol no permiten cambios.">
        <q-btn color="primary" unelevated label="Ver detalle" :to="backTo" />
      </EmptyState>

      <q-card v-else-if="!loading" flat bordered>
        <q-form class="q-pa-md q-gutter-y-md" novalidate @submit.prevent="submit">
          <div v-if="canEditDetails">
            <div class="text-caption text-grey-8 q-mb-xs">Tipo</div>
            <q-btn-toggle
              v-model="form.type"
              :options="catalogs.catalogs?.types ?? []"
              no-caps
              unelevated
              toggle-color="primary"
              color="grey-3"
              text-color="grey-9"
              @update:model-value="clearField('type')"
            />
          </div>

          <q-input
            v-model="form.title"
            label="Título *"
            outlined
            counter
            maxlength="150"
            :readonly="!canEditDetails"
            :rules="rules.title"
            lazy-rules
            :error="!!errors.title"
            :error-message="errors.title"
            @update:model-value="clearField('title')"
          />

          <q-input
            v-model="form.description"
            label="Descripción *"
            type="textarea"
            autogrow
            outlined
            counter
            maxlength="5000"
            :readonly="!canEditDetails"
            :rules="rules.description"
            lazy-rules
            :error="!!errors.description"
            :error-message="errors.description"
            @update:model-value="clearField('description')"
          />

          <div class="row q-col-gutter-md">
            <div class="col-12 col-sm-6">
              <q-select
                v-model="form.priority"
                :options="catalogs.catalogs?.priorities ?? []"
                label="Prioridad *"
                emit-value
                map-options
                outlined
                :readonly="!canEditPriority"
                :hint="isEdit && !canEditPriority ? 'Solo el responsable o un administrador la cambia.' : undefined"
                :error="!!errors.priority"
                :error-message="errors.priority"
                @update:model-value="clearField('priority')"
              />
            </div>
            <div class="col-12 col-sm-6">
              <q-input
                v-model="form.due_at"
                type="datetime-local"
                label="Fecha de vencimiento (hora Colombia)"
                stack-label
                outlined
                :readonly="!canEditPriority"
                :hint="isEdit ? undefined : 'Si lo dejas vacío se calcula según la prioridad.'"
                :rules="rules.due_at"
                lazy-rules
                :error="!!errors.due_at"
                :error-message="errors.due_at"
                @update:model-value="clearField('due_at')"
              />
            </div>
          </div>

          <AssigneeSelect
            v-if="canAssign"
            v-model="form.assigned_to"
            label="Responsable (opcional)"
            :self-only="!isAdmin"
            :error-message="errors.assigned_to"
            @update:model-value="clearField('assigned_to')"
          />

          <div class="row justify-end q-gutter-sm">
            <q-btn flat no-caps label="Cancelar" :to="backTo" />
            <q-btn
              type="submit"
              unelevated
              no-caps
              color="primary"
              :label="isEdit ? 'Guardar cambios' : 'Registrar'"
              :loading="saving"
              :disable="isEdit && !dirty"
            />
          </div>
        </q-form>
      </q-card>
    </div>
  </q-page>
</template>

<style scoped>
.form-wrap {
  max-width: 760px;
  margin: 0 auto;
}
</style>
