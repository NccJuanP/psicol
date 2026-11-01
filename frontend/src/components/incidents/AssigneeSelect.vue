<script setup lang="ts">
import { computed, ref } from 'vue'
import { usersApi } from '@/api/users.api'
import { useAuthStore } from '@/stores/auth.store'
import type { UserSummary } from '@/types/api'

const props = withDefaults(
  defineProps<{
    label?: string
    /** Current assignee, so the field can show its name before any search. */
    initial?: UserSummary | null
    /** Agents may only assign themselves (IncidentPolicy); admins may pick anyone. */
    selfOnly?: boolean
    errorMessage?: string
  }>(),
  { label: 'Responsable', initial: null, selfOnly: false, errorMessage: undefined },
)
const model = defineModel<number | null>({ default: null })

const auth = useAuthStore()
const found = ref<UserSummary[]>([])

const options = computed(() => {
  const me = auth.user ? [{ id: auth.user.id, name: `${auth.user.name} (yo)` }] : []
  const pool = props.selfOnly ? me : [...me, ...found.value.filter((user) => user.id !== auth.user?.id)]
  if (props.initial && !pool.some((user) => user.id === props.initial?.id)) pool.push(props.initial)
  return pool.map((user) => ({ label: user.name, value: user.id }))
})

function onFilter(search: string, done: (callback: () => void) => void, abort: () => void): void {
  if (props.selfOnly) return done(() => undefined)
  usersApi
    .assignable(search)
    .then((users) => done(() => (found.value = users)))
    .catch(() => abort())
}
</script>

<template>
  <q-select
    v-model="model"
    :label="label"
    :options="options"
    emit-value
    map-options
    clearable
    outlined
    :use-input="!selfOnly"
    input-debounce="300"
    :error="!!errorMessage"
    :error-message="errorMessage"
    @filter="onFilter"
  >
    <template #prepend><q-icon name="person_outline" /></template>
    <template #no-option>
      <q-item><q-item-section class="text-grey-7">Sin resultados</q-item-section></q-item>
    </template>
  </q-select>
</template>
