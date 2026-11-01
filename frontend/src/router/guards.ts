import type { Router } from 'vue-router'
import { useAuthStore } from '@/stores/auth.store'

/** Only same-app paths: "//evil.com" or "https://…" would turn the login redirect into an open redirect. */
export function safeRedirect(value: unknown): string | null {
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : null
}

export function installGuards(router: Router): void {
  router.beforeEach((to) => {
    const auth = useAuthStore()

    if (to.meta.guestOnly && auth.isAuthenticated) {
      return safeRedirect(to.query.redirect) ?? { name: 'dashboard' }
    }

    if (to.meta.requiresAuth && !auth.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath, ...(auth.sessionExpired && { reason: 'expired' }) } }
    }

    if (to.meta.permission && !auth.can(to.meta.permission)) {
      return { name: 'forbidden' }
    }
  })
}
