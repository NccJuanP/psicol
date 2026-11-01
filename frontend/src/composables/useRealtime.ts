import { onUnmounted } from 'vue'
import { getEcho } from '@/plugins/echo'
import { useAuthStore } from '@/stores/auth.store'
import type { UserSummary } from '@/types/api'

/** Payload of docs/api-contract.md, section 8. Never rendered: it only says what to refetch. */
export interface RealtimeEvent {
  id: number
  code: string
  event: RealtimeEventName
  changes: string[]
  actor: UserSummary | null
  occurred_at: string
}

export type RealtimeEventName =
  | 'incident.created'
  | 'incident.updated'
  | 'incident.status_changed'
  | 'incident.assigned'
  | 'incident.deleted'
  | 'incident.assigned_to_you'

const CHANGE_EVENTS: RealtimeEventName[] = [
  'incident.created',
  'incident.updated',
  'incident.status_changed',
  'incident.assigned',
  'incident.deleted',
]

/**
 * Listens while the calling component is mounted and stops on unmount. Staff hear every change on
 * incidents.staff; everyone hears changes to their own cases on users.{id}. The private channels stay
 * joined (other components share them); only this component's callbacks are removed.
 */
export function useRealtime(
  handler: (event: RealtimeEvent) => void,
  events: RealtimeEventName[] = CHANGE_EVENTS,
): void {
  const echo = getEcho()
  const auth = useAuthStore()
  if (!echo || !auth.user) return

  const isStaff = auth.user.roles.some((role) => role === 'admin' || role === 'agent')
  const channels = [`users.${auth.user.id}`, ...(isStaff ? ['incidents.staff'] : [])]

  for (const name of channels) {
    const channel = echo.private(name)
    for (const event of events) channel.listen(`.${event}`, handler)
  }

  onUnmounted(() => {
    for (const name of channels) {
      const channel = echo.private(name)
      for (const event of events) channel.stopListening(`.${event}`, handler)
    }
  })
}
