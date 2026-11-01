import axios, { type AxiosError } from 'axios'
import { Notify } from 'quasar'
import { useAuthStore } from '@/stores/auth.store'
import type { ApiError } from '@/types/api'

/** The only Axios instance. Components never import it: they go through the *.api.ts modules. */
export const http = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

// crypto.randomUUID only exists in secure contexts (https or localhost); fall back for plain-http LAN access.
const requestId = (): string =>
  crypto.randomUUID?.() ??
  Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('')

http.interceptors.request.use((config) => {
  const { token } = useAuthStore()
  if (token) config.headers.Authorization = `Bearer ${token}`
  config.headers['X-Request-Id'] = requestId()
  return config
})

function toApiError(error: AxiosError<ApiError>): ApiError {
  const data = error.response?.data
  if (data && typeof data === 'object' && 'code' in data) return data
  return error.response
    ? { message: 'Ocurrió un error inesperado.', code: 'SERVER_ERROR' }
    : { message: 'No se pudo conectar con el servidor. Revisa tu conexión.', code: 'SERVER_ERROR' }
}

/**
 * Normalizes every failure to ApiError and reacts as in docs/api-contract.md. 404 and 422 are left to the
 * view (not-found page, field errors); the promise is always rejected so the view can still decide.
 */
http.interceptors.response.use(undefined, async (error: AxiosError<ApiError>) => {
  const apiError = toApiError(error)
  const status = error.response?.status ?? 0

  if (status === 401 && error.config?.url !== '/auth/login') {
    useAuthStore().clear(true)
    const { router } = await import('@/router') // lazy: the router imports stores that import this module
    const here = window.location.pathname + window.location.search
    if (router.currentRoute.value.name !== 'login') {
      await router.push({ name: 'login', query: { redirect: here, reason: 'expired' } })
    }
  } else if (status === 403) {
    Notify.create({ type: 'negative', message: 'No tienes permiso para esta acción.' })
  } else if (status === 409) {
    Notify.create({ type: 'warning', message: apiError.message })
  } else if (status === 429) {
    Notify.create({ type: 'warning', message: apiError.message, caption: 'Espera unos segundos e inténtalo de nuevo.' })
  } else if (status === 0 || status >= 500) {
    Notify.create({
      type: 'negative',
      message: apiError.message,
      caption: apiError.request_id ? `Código de soporte: ${apiError.request_id}` : undefined,
    })
  }

  return Promise.reject(apiError)
})

export function isApiError(value: unknown): value is ApiError {
  return typeof value === 'object' && value !== null && 'code' in value && 'message' in value
}
