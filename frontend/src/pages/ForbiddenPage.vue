<script setup lang="ts">
import EmptyState from '@/components/common/EmptyState.vue'
</script>

<template>
  <q-page padding class="flex flex-center">
    <EmptyState icon="lock" title="No tienes permiso para ver esta página" message="Si crees que es un error, pide acceso a un administrador.">
      <q-btn color="primary" unelevated label="Ir al inicio" :to="{ name: 'dashboard' }" />
    </EmptyState>
  </q-page>
</template>
