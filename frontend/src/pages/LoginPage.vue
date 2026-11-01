<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { isApiError } from '@/api/http'
import { useServerErrors } from '@/composables/useServerErrors'
import { safeRedirect } from '@/router/guards'
import { useAuthStore } from '@/stores/auth.store'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { errors, setFromError, clearField } = useServerErrors()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const submitting = ref(false)
const throttled = ref<string | null>(null)

const expired = computed(() => route.query.reason === 'expired')

// Same basic rules as LoginRequest; the backend stays the source of truth (D-23).
const rules = {
  email: [
    (v: string) => !!v.trim() || 'El correo electrónico es obligatorio.',
    (v: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()) || 'Escribe un correo electrónico válido.',
  ],
  password: [(v: string) => !!v || 'La contraseña es obligatoria.'],
}

async function submit(): Promise<void> {
  submitting.value = true
  throttled.value = null
  try {
    await auth.login(email.value.trim(), password.value)
    await router.replace(safeRedirect(route.query.redirect) ?? { name: 'dashboard' })
  } catch (error) {
    if (!setFromError(error) && isApiError(error) && error.code === 'TOO_MANY_REQUESTS') {
      throttled.value = 'Demasiados intentos. Espera un minuto antes de volver a intentarlo.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <q-card flat bordered class="q-pa-md">
    <q-card-section>
      <h2 class="text-h6 q-mt-none q-mb-md">Iniciar sesión</h2>

      <q-banner v-if="expired" dense rounded class="bg-orange-1 text-orange-10 q-mb-md" role="status">
        <template #avatar><q-icon name="schedule" /></template>
        Tu sesión expiró o se cerró. Inicia sesión de nuevo.
      </q-banner>
      <q-banner v-if="throttled" dense rounded class="bg-red-1 text-negative q-mb-md" role="alert">
        <template #avatar><q-icon name="block" /></template>
        {{ throttled }}
      </q-banner>

      <q-form class="q-gutter-y-md" novalidate @submit.prevent="submit">
        <q-input
          v-model="email"
          type="email"
          label="Correo electrónico"
          autocomplete="username"
          autofocus
          outlined
          lazy-rules
          :rules="rules.email"
          :error="!!errors.email"
          :error-message="errors.email"
          @update:model-value="clearField('email')"
        >
          <template #prepend><q-icon name="mail_outline" /></template>
        </q-input>

        <q-input
          v-model="password"
          :type="showPassword ? 'text' : 'password'"
          label="Contraseña"
          autocomplete="current-password"
          outlined
          lazy-rules
          :rules="rules.password"
          :error="!!errors.password"
          :error-message="errors.password"
          @update:model-value="clearField('password')"
        >
          <template #prepend><q-icon name="lock_outline" /></template>
          <template #append>
            <q-btn
              flat
              dense
              round
              :icon="showPassword ? 'visibility_off' : 'visibility'"
              :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
              @click="showPassword = !showPassword"
            />
          </template>
        </q-input>

        <q-btn type="submit" color="primary" label="Ingresar" class="full-width" unelevated size="md" :loading="submitting" />
      </q-form>
    </q-card-section>
  </q-card>
</template>
