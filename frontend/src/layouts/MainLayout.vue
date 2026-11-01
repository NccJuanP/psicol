<script setup lang="ts">
import { useQuasar } from 'quasar'
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { Role } from '@/types/api'

const ROLE_LABELS: Record<Role, string> = { admin: 'Administrador', agent: 'Agente', requester: 'Solicitante' }

const $q = useQuasar()
const router = useRouter()
const auth = useAuthStore()
const catalogs = useCatalogsStore()

// Desktop: persistent drawer that collapses to icons. Below the breakpoint Quasar overlays it instead.
const drawerOpen = ref(false)
const mini = ref(false)

const roleLabel = computed(() => auth.user?.roles.map((role) => ROLE_LABELS[role]).join(', ') ?? '')
const links = computed(() =>
  [
    { to: { name: 'dashboard' }, icon: 'dashboard', label: 'Dashboard', permission: 'dashboard.view' },
    { to: { name: 'incidents' }, icon: 'list_alt', label: 'Incidentes', permission: null },
    { to: { name: 'incident-create' }, icon: 'add_circle_outline', label: 'Nuevo incidente', permission: 'incidents.create' },
  ].filter((link) => link.permission === null || auth.can(link.permission)),
)

function toggleDrawer(): void {
  if ($q.screen.gt.sm) mini.value = !mini.value
  else drawerOpen.value = !drawerOpen.value
}

async function logout(): Promise<void> {
  await auth.logout()
  await router.push({ name: 'login' })
}

onMounted(() => catalogs.load().catch(() => undefined)) // the interceptor already notified
</script>

<template>
  <q-layout view="hHh LpR lFf">
    <q-header elevated class="bg-primary text-white">
      <q-toolbar>
        <q-btn flat dense round icon="menu" aria-label="Mostrar u ocultar el menú" @click="toggleDrawer" />
        <q-toolbar-title class="text-subtitle1 text-weight-medium">Psicol · Incidentes</q-toolbar-title>

        <div class="row items-center no-wrap q-gutter-x-sm">
          <div class="text-right gt-xs">
            <div class="text-body2 ellipsis">{{ auth.user?.name }}</div>
            <div class="text-caption text-blue-1">{{ roleLabel }}</div>
          </div>
          <q-btn flat dense round icon="logout" aria-label="Cerrar sesión" @click="logout">
            <q-tooltip>Cerrar sesión</q-tooltip>
          </q-btn>
        </div>
      </q-toolbar>
    </q-header>

    <q-drawer
      v-model="drawerOpen"
      show-if-above
      bordered
      :mini="mini && $q.screen.gt.sm"
      :width="240"
      :breakpoint="1024"
      class="bg-white"
    >
      <q-list padding>
        <q-item v-if="$q.screen.lt.md" class="q-mb-sm">
          <q-item-section>
            <q-item-label class="text-weight-medium">{{ auth.user?.name }}</q-item-label>
            <q-item-label caption>{{ roleLabel }}</q-item-label>
          </q-item-section>
        </q-item>

        <q-item v-for="link in links" :key="link.label" :to="link.to" clickable active-class="text-primary bg-blue-1" exact>
          <q-item-section avatar>
            <q-icon :name="link.icon" />
          </q-item-section>
          <q-item-section>{{ link.label }}</q-item-section>
          <q-tooltip v-if="mini && $q.screen.gt.sm" anchor="center right" self="center left">{{ link.label }}</q-tooltip>
        </q-item>
      </q-list>
    </q-drawer>

    <q-page-container>
      <router-view />
    </q-page-container>
  </q-layout>
</template>
