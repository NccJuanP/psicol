<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'

defineProps<{ label: string; value: number; icon: string; color: string; caption?: string; to: RouteLocationRaw }>()
</script>

<template>
  <q-card flat bordered class="stat-card full-height">
    <router-link
      :to="to"
      class="stat-link column q-pa-md full-height"
      :aria-label="`${label}: ${value}. Ver en el listado`"
    >
      <div class="row items-center no-wrap">
        <q-icon :name="icon" :color="color" size="22px" aria-hidden="true" />
        <span class="q-ml-sm text-grey-8 text-body2">{{ label }}</span>
      </div>
      <div class="text-h4 text-weight-medium q-mt-sm text-dark">{{ value.toLocaleString('es-CO') }}</div>
      <div v-if="caption" class="text-caption text-grey-7">{{ caption }}</div>
    </router-link>
  </q-card>
</template>

<style scoped>
.stat-link {
  color: inherit;
  text-decoration: none;
  border-radius: inherit;
}

.stat-card:hover {
  border-color: var(--q-primary);
}

.stat-link:focus-visible {
  outline: 2px solid var(--q-primary);
  outline-offset: 2px;
}
</style>
