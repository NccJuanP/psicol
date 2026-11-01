import '@quasar/extras/material-icons/material-icons.css'
import 'quasar/src/css/index.sass'
import './css/app.css'

import { createPinia } from 'pinia'
import { Quasar } from 'quasar'
import { createApp } from 'vue'
import App from './App.vue'
import { quasarOptions } from './plugins/quasar'
import { router } from './router'
import { useAuthStore } from './stores/auth.store'

const app = createApp(App).use(createPinia()).use(Quasar, quasarOptions)

// Restore the session before the router runs its guards, so a reload never bounces through /login.
useAuthStore()
  .hydrate()
  .finally(() => app.use(router).mount('#app'))
