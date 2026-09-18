import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * Comandas y pantalla de cocina (F1-B).
 *
 * El KDS es una pantalla de pared: se refresca sola, no tiene a nadie sentado
 * delante y sus tres acciones se tocan con las manos ocupadas. Por eso el sondeo
 * es más frecuente que en el salón —lo que espera en el pase envejece rápido— y
 * por eso no hay confirmaciones: marcar lista una comanda que no lo estaba se
 * arregla mirando el pase, no con un diálogo.
 */

/** `held` es la comanda pedida y todavía sin marchar (G-16): no está en el pase. */
export type TicketStatus = 'held' | 'queued' | 'preparing' | 'ready' | 'served' | 'cancelled'

export interface KitchenLine {
  id: string
  name: string
  qty: string
  modifiers: string[]
  notes: string | null
}

export interface KitchenTicket {
  id: string
  number: number
  destination: string
  /** Curso del servicio: entrada, fuerte, postre (G-16). */
  course: number
  status: TicketStatus
  sale_id: string
  label: string | null
  notes: string | null
  sent_at: string
  /** Cuándo se marchó. Nulo mientras está retenida: es lo que le falta. */
  fired_at: string | null
  started_at: string | null
  ready_at: string | null
  /**
   * Los dos los calcula el servidor.
   *
   * El KDS cuelga de una pared y **su reloj no es el de nadie**: un navegador
   * con la hora corrida pintaría media cocina de rojo. Y el umbral de atraso es
   * del negocio, no de la pantalla.
   */
  elapsed_seconds: number
  is_late: boolean
  lines: KitchenLine[]
}

export interface PendingLine {
  sale_line_id: string
  name: string
  qty: string
  destination: string | null
  course: number
  modifiers: string[]
  notes: string | null
}

const POLL_MS = 4_000

export function useKitchen() {
  const tickets = shallowRef<KitchenTicket[]>([])
  const loading = ref(false)
  const saving = ref(false)
  const polling = ref<ReturnType<typeof setInterval> | null>(null)

  async function refresh(destination?: string) {
    loading.value = true

    try {
      const query = destination ? `?destination=${encodeURIComponent(destination)}` : ''
      tickets.value = await apiGetList<KitchenTicket>(`/kitchen/tickets${query}`)
    } finally {
      loading.value = false
    }
  }

  function start(destination?: string) {
    if (polling.value) return

    void refresh(destination).catch(() => undefined)
    polling.value = setInterval(() => void refresh(destination).catch(() => undefined), POLL_MS)
  }

  function stop() {
    if (!polling.value) return

    clearInterval(polling.value)
    polling.value = null
  }

  /** Avanza la comanda. El servidor rechaza los saltos hacia atrás. */
  async function advance(id: string, status: TicketStatus, destination?: string) {
    saving.value = true

    try {
      await apiFetch(`/kitchen/tickets/${id}/status`, {
        method: 'POST',
        body: JSON.stringify({ status })
      })

      await refresh(destination)
    } finally {
      saving.value = false
    }
  }

  /** Lo que la cuenta todavía no mandó: el mesero lo ve antes de comandar. */
  async function pending(saleId: string): Promise<PendingLine[]> {
    return apiGetList<PendingLine>(`/sales/${saleId}/kitchen/pending`)
  }

  async function send(saleId: string, notes?: string): Promise<KitchenTicket[]> {
    saving.value = true

    try {
      return await apiFetch<KitchenTicket[]>(`/sales/${saleId}/kitchen/send`, {
        method: 'POST',
        body: JSON.stringify({ notes: notes?.trim() || null })
      })
    } finally {
      saving.value = false
    }
  }

  /**
   * Marcha el curso que sigue (G-16).
   *
   * Sin curso, el servidor marcha el más viejo de los retenidos: el botón del
   * salón dice "marchar lo que sigue" y eso es un curso, no todo lo pedido.
   */
  async function fire(saleId: string, course?: number): Promise<KitchenTicket[]> {
    saving.value = true

    try {
      return await apiFetch<KitchenTicket[]>(`/sales/${saleId}/kitchen/fire`, {
        method: 'POST',
        body: JSON.stringify({ course: course ?? null })
      })
    } finally {
      saving.value = false
    }
  }

  /**
   * Minutos que lleva esperando, según el servidor.
   *
   * Se redondea acá y no allá porque los segundos son del dato y los minutos de
   * la pantalla — el pase no lee "1.247 segundos".
   */
  function waiting(ticket: KitchenTicket): number {
    return Math.max(0, Math.round(ticket.elapsed_seconds / 60))
  }

  return { tickets, loading, saving, refresh, start, stop, advance, pending, send, fire, waiting }
}
