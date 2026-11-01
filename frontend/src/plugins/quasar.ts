import { Dialog, Loading, Notify, type QuasarPluginOptions } from 'quasar'
import iconSet from 'quasar/icon-set/material-icons'
import langEs from 'quasar/lang/es'

export const quasarOptions: Partial<QuasarPluginOptions> = {
  lang: langEs,
  iconSet,
  plugins: { Notify, Dialog, Loading },
  config: {
    brand: {
      primary: '#1f4e79',
      secondary: '#2e7d9a',
      accent: '#f2a93b',
      dark: '#1d1f24',
      positive: '#2e7d32',
      negative: '#c62828',
      info: '#0277bd',
      warning: '#ed6c02',
    },
    notify: { position: 'top-right', timeout: 4000, actions: [{ icon: 'close', color: 'white', round: true }] },
  },
}
