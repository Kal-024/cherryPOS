import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Estado de conexión y envíos pendientes.
 *
 * Regla transversal de interfaz: **siempre visible**. El cajero tiene que poder
 * responder "¿esto se guardó?" sin preguntarle a nadie, sobre todo con el modo
 * degradado de H6 en el alcance.
 *
 * Hoy solo refleja el estado del navegador; cuando exista la bandeja de salida
 * (H6.2) contará también los tickets sin enviar.
 */
const online = ref(navigator.onLine)
const pending = ref(0)

window.addEventListener('online', () => (online.value = true))
window.addEventListener('offline', () => (online.value = false))

export function useConnection() {
  const { t } = useI18n()

  const label = computed(() => {
    if (!online.value) return t('connection.degraded')
    if (pending.value > 0) return t('connection.pending', { count: pending.value })
    return t('connection.online')
  })

  const color = computed(() => {
    if (!online.value) return 'error' as const
    if (pending.value > 0) return 'warning' as const
    return 'success' as const
  })

  return { online, pending, label, color }
}
