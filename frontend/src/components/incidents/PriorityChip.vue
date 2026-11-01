<script setup lang="ts">
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { Priority } from '@/types/api'
import { PRIORITY_LOOK } from '@/utils/presentation'

defineProps<{ priority: Priority }>()
const catalogs = useCatalogsStore()
</script>

<template>
  <span class="row inline items-center no-wrap" :class="`text-${PRIORITY_LOOK[priority].color}`">
    <q-icon :name="PRIORITY_LOOK[priority].icon" size="18px" aria-hidden="true" />
    <span class="q-ml-xs text-weight-medium">{{ catalogs.priorityLabel(priority) }}</span>
  </span>
</template>
