import '@quasar/extras/material-icons/material-icons.css'
import 'quasar/src/css/index.sass'
import './css/app.css'

import { createPinia } from 'pinia'
import { Quasar } from 'quasar'
import { createApp, watch } from 'vue'
import App from './App.vue'
import { connectEcho, disconnectEcho } from './plugins/echo'
import { quasarOptions } from './plugins/quasar'
import { router } from './router'
import { useAuthStore } from './stores/auth.store'

const app = createApp(App).use(createPinia()).use(Quasar, quasarOptions)
const auth = useAuthStore()

// Restore the session before the router runs its guards, so a reload never bounces through /login.
auth.hydrate().finally(() => {
  // Echo lives exactly as long as the session: connect after login, disconnect on logout or 401.
  watch(
    () => (auth.isAuthenticated ? auth.token : null),
    (token) => (token ? connectEcho(token) : disconnectEcho()),
    { immediate: true },
  )
  app.use(router).mount('#app')
})
