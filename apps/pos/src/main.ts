import './assets/css/main.css'

import { createApp } from 'vue'
import type { RouteRecordRaw } from 'vue-router'
import { createRouter, createWebHistory } from 'vue-router'
import { handleHotUpdate, routes } from 'vue-router/auto-routes'
import { setupLayouts } from 'virtual:generated-layouts'
import { createHead } from '@unhead/vue/client'
import ui from '@nuxt/ui/vue-plugin'

import App from './App.vue'
import { i18n } from './i18n'
import { useOperator } from './composables/useOperator'
import { useTerminal } from './composables/useTerminal'

const app = createApp(App)

const head = createHead()
const router = createRouter({
  routes: setupLayouts(routes as RouteRecordRaw[]),
  history: createWebHistory()
})

/**
 * Los dos anillos de la doble credencial, hechos guardia de ruta (D-05).
 *
 * Sin terminal activada no hay nada que hacer salvo activarla; con terminal
 * pero sin cajero, solo se puede ingresar el PIN. Es literalmente la
 * "calculadora cerrada" de la decisión.
 */
/**
 * La sesión del cajero se relee **una vez** al arrancar.
 *
 * Sin esto, recargar la página mandaba al PIN aunque la sesión siguiera viva en
 * el servidor: el guardia corría antes de que la aplicación preguntara quién
 * está operando. En una caja eso es apretar F5 sin querer y tener que volver a
 * identificarse delante del cliente.
 */
let sessionChecked = false

router.beforeEach(async (to) => {
  const { isActivated } = useTerminal()
  const { isSignedIn, can, refresh } = useOperator()

  if (!isActivated.value) {
    return to.path === '/terminal' ? true : '/terminal'
  }

  if (!isSignedIn.value && !sessionChecked) {
    sessionChecked = true
    await refresh().catch(() => undefined)
  }

  if (!isSignedIn.value) {
    return to.path === '/login' ? true : '/login'
  }

  if (to.path === '/terminal' || to.path === '/login') return '/'

  // Tercer anillo, el de H7: la pantalla que exige un permiso lo declara en su
  // `meta`. No sustituye al permiso del endpoint —ese es el que manda— pero
  // evita el callejón sin salida de una pantalla que carga para terminar en un
  // 403 que el cajero no puede resolver.
  const permission = to.meta.permission

  if (typeof permission === 'string' && !can(permission)) return '/'

  return true
})

app.use(head)
app.use(router)
app.use(ui)
app.use(i18n)

app.mount('#app')

if (import.meta.hot) {
  handleHotUpdate(router)
}
