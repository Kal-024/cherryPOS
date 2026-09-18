import { computed, ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'
import { useOperator } from './useOperator'

/**
 * Bandeja de avisos al supervisor (P-11, H7.5).
 *
 * **Interna y no bloqueante.** El caso que originó la decisión es el cajero
 * pasando productos sin registrar: hay que avisar rápido, no parar la fila. Por
 * eso es una bandeja con aviso en pantalla y nunca un diálogo que exija
 * atenderlo antes de seguir cobrando.
 *
 * Se sondea, como todo lo demás entre terminales (G-13, R-01): ocurre en la red
 * local, la latencia no se nota y un servidor de conexiones persistentes sería
 * una pieza más que operar en el equipo de cada cliente.
 */

export interface SupervisorNotification {
  id: string
  event: string
  severity: 'info' | 'warning' | 'critical'
  title: string
  body: string | null
  context: Record<string, unknown> | null
  occurred_at: string
  read_at: string | null
}

const notifications = shallowRef<SupervisorNotification[]>([])
const polling = ref<ReturnType<typeof setInterval> | null>(null)

/** Un minuto: el aviso es para actuar después, no para interrumpir la venta. */
const POLL_MS = 60_000

export function useSupervision() {
  const { can } = useOperator()

  const unread = computed(() => notifications.value.filter((item) => item.read_at === null))
  const hasCritical = computed(() => unread.value.some((item) => item.severity === 'critical'))

  async function refresh() {
    if (!can('supervisor.notifications')) {
      notifications.value = []

      return
    }

    notifications.value = await apiGetList<SupervisorNotification>('/supervision/notifications?unread_only=1')
  }

  async function markRead(id: string) {
    await apiFetch(`/supervision/notifications/${id}/read`, { method: 'POST' })

    notifications.value = notifications.value.filter((item) => item.id !== id)
  }

  function start() {
    if (polling.value) return

    void refresh().catch(() => undefined)
    // Un fallo del sondeo no puede hacer ruido: sin red, la caja sigue
    // vendiendo y los avisos esperan.
    polling.value = setInterval(() => void refresh().catch(() => undefined), POLL_MS)
  }

  function stop() {
    if (!polling.value) return

    clearInterval(polling.value)
    polling.value = null
  }

  return { notifications, unread, hasCritical, refresh, markRead, start, stop }
}
