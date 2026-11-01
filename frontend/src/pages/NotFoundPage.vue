<script setup lang="ts">
import EmptyState from '@/components/common/EmptyState.vue'
</script>

<template>
  <q-layout view="hHh lpR fFf">
    <q-page-container>
      <q-page class="flex flex-center">
        <EmptyState icon="search_off" title="Página no encontrada" message="La dirección no existe o fue movida.">
          <q-btn color="primary" unelevated label="Ir al inicio" :to="{ name: 'dashboard' }" />
        </EmptyState>
      </q-page>
    </q-page-container>
  </q-layout>
</template>
