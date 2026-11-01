<script setup lang="ts">
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { IncidentStatus } from '@/types/api'
import { STATUS_LOOK } from '@/utils/presentation'

defineProps<{ status: IncidentStatus }>()
const catalogs = useCatalogsStore()
</script>

<template>
  <q-badge :color="STATUS_LOOK[status].color" class="q-py-xs q-px-sm text-weight-medium">
    <q-icon :name="STATUS_LOOK[status].icon" size="14px" class="q-mr-xs" aria-hidden="true" />
    {{ catalogs.statusLabel(status) }}
  </q-badge>
</template>
