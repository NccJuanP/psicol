import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { ref } from 'vue'

declare global {
  interface Window {
    Pusher: typeof Pusher
  }
}
window.Pusher = Pusher // Echo's reverb connector looks for the Pusher client here

export type ConnectionState = 'connected' | 'connecting' | 'disconnected'

/** Header indicator; the app keeps working over HTTP whatever this says (docs/api-contract.md, section 8). */
export const connectionState = ref<ConnectionState>('disconnected')

let echo: Echo<'reverb'> | null = null

/** Same origin as the SPA: the gateway routes /app to Reverb and /api/broadcasting/auth to Laravel (D-20). */
export function connectEcho(token: string): void {
  disconnectEcho()
  const secure = location.protocol === 'https:'
  const port = Number(location.port) || (secure ? 443 : 80)

  echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
    auth: { headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' } },
  })

  connectionState.value = 'connecting'
  // pusher-js reconnects on its own with backoff; we only mirror its state.
  echo.connector.pusher.connection.bind('state_change', ({ current }: { current: string }) => {
    connectionState.value = current === 'connected' ? 'connected' : current === 'connecting' ? 'connecting' : 'disconnected'
  })
}

export function disconnectEcho(): void {
  echo?.disconnect()
  echo = null
  connectionState.value = 'disconnected'
}

export const getEcho = (): Echo<'reverb'> | null => echo
