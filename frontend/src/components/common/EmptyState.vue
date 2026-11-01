<script setup lang="ts">
defineProps<{ icon: string; title: string; message?: string }>()
</script>

<template>
  <div class="column items-center text-center q-pa-xl text-grey-8">
    <q-icon :name="icon" size="56px" color="grey-5" aria-hidden="true" />
    <div class="text-h6 q-mt-md">{{ title }}</div>
    <p v-if="message" class="q-mt-sm q-mb-none" style="max-width: 420px">{{ message }}</p>
    <div class="q-mt-md"><slot /></div>
  </div>
</template>
