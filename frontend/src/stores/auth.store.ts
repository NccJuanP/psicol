import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { authApi } from '@/api/auth.api'
import type { AuthUser } from '@/types/api'

const STORAGE_KEY = 'incidents.auth'

interface StoredSession {
  token: string
  expires_at: string
}

// Storage can be unavailable (private mode, blocked site data): the session then just lives in memory.
function readSession(): StoredSession | null {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as StoredSession) : null
  } catch {
    return null
  }
}

function writeSession(session: StoredSession | null): void {
  try {
    if (session) localStorage.setItem(STORAGE_KEY, JSON.stringify(session))
    else localStorage.removeItem(STORAGE_KEY)
  } catch {
    /* in-memory only */
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(null)
  const expiresAt = ref<string | null>(null)
  const user = ref<AuthUser | null>(null)
  /** True when the last session ended by expiry or revocation, so the login page can say so. */
  const sessionExpired = ref(false)

  const isAuthenticated = computed(() => token.value !== null && user.value !== null)

  function can(permission: string): boolean {
    return user.value?.permissions.includes(permission) ?? false
  }

  async function login(email: string, password: string): Promise<void> {
    const session = await authApi.login(email, password)
    token.value = session.token
    expiresAt.value = session.expires_at
    user.value = session.user
    sessionExpired.value = false
    writeSession({ token: session.token, expires_at: session.expires_at })
  }

  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } catch {
      /* the token may already be expired or revoked: the local session ends either way */
    } finally {
      clear()
    }
  }

  function clear(expired = false): void {
    token.value = null
    expiresAt.value = null
    user.value = null
    sessionExpired.value = expired
    writeSession(null)
  }

  /** On boot: drop an expired token, otherwise load the user behind it (a revoked one answers 401). */
  async function hydrate(): Promise<void> {
    const saved = readSession()
    if (!saved) return

    if (Date.parse(saved.expires_at) <= Date.now()) {
      clear(true)
      return
    }

    token.value = saved.token
    expiresAt.value = saved.expires_at
    try {
      user.value = await authApi.me()
    } catch {
      clear(true)
    }
  }

  return { token, expiresAt, user, sessionExpired, isAuthenticated, can, login, logout, clear, hydrate }
})
