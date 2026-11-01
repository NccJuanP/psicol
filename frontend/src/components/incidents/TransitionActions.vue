<script setup lang="ts">
import { useQuasar } from 'quasar'
import type { IncidentDetail, IncidentStatus } from '@/types/api'
import { transitionIcon, transitionLabel } from '@/utils/presentation'

/** Buttons come only from allowed_transitions: the backend decides, the UI names them (D-24). */
const props = defineProps<{ incident: IncidentDetail; busy: boolean }>()
const emit = defineEmits<{ transition: [status: IncidentStatus, note?: string] }>()
const $q = useQuasar()

function run(to: IncidentStatus): void {
  const from = props.incident.status
  if (from === 'resolved' && to === 'in_progress') {
    // R-12: reopening needs a reason.
    $q.dialog({
      title: 'Reabrir caso',
      message: 'Explica por qué se reabre (entre 10 y 500 caracteres).',
      prompt: { model: '', type: 'textarea', counter: true, maxlength: 500, isValid: (v: string) => v.trim().length >= 10 },
      cancel: { label: 'Cancelar', flat: true },
      ok: { label: 'Reabrir', color: 'primary', unelevated: true },
      persistent: true,
    }).onOk((note: string) => emit('transition', to, note.trim()))
  } else if (to === 'cancelled' || to === 'closed') {
    $q.dialog({
      title: `${transitionLabel(from, to)} ${props.incident.code}`,
      message: to === 'cancelled' ? 'El caso se retira sin resolverse y ya no admitirá cambios.' : 'Confirmas que la solución es correcta. El caso ya no admitirá cambios.',
      cancel: { label: 'Volver', flat: true },
      ok: { label: transitionLabel(from, to), color: to === 'cancelled' ? 'negative' : 'primary', unelevated: true },
    }).onOk(() => emit('transition', to))
  } else {
    emit('transition', to)
  }
}
</script>

<template>
  <div v-if="incident.allowed_transitions.length" class="row q-gutter-sm">
    <q-btn
      v-for="to in incident.allowed_transitions"
      :key="to"
      no-caps
      :unelevated="to !== 'cancelled'"
      :outline="to === 'cancelled'"
      :color="to === 'cancelled' ? 'negative' : 'primary'"
      :icon="transitionIcon[to]"
      :label="transitionLabel(incident.status, to)"
      :disable="busy"
      @click="run(to)"
    />
  </div>
</template>
