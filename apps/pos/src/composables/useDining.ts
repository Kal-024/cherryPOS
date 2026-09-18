import { computed, ref, shallowRef } from 'vue'
import { apiFetch } from './httpClient'

/**
 * El salón (F1-B, §10).
 *
 * El mapa llega entero en una consulta y se refresca por **sondeo**, como todo
 * lo que ocurre entre terminales (G-13, R-01): en una red local la latencia no
 * se nota, y un servidor de conexiones persistentes sería una pieza más que
 * operar en el equipo de cada cliente.
 *
 * El estado de la mesa **no se guarda en ningún lado**: lo deduce el servidor de
 * la venta suspendida que la referencia (D-01). Por eso este composable no tiene
 * "marcar mesa ocupada": no existe tal cosa.
 */

export interface DiningArea {
  id: string
  code: string
  name: string
}

/**
 * En qué anda el pedido de la mesa (G-16).
 *
 * `ready` manda sobre `cooking` y `cooking` sobre `held`, porque el orden es el
 * del mesero: lo listo es lo único que exige caminar ahora mismo.
 */
export interface TableKitchenState {
  state: 'cooking' | 'ready' | 'held'
  /** Cursos pedidos que todavía esperan a que alguien los marche. */
  held_courses: number[]
}

export interface TableSale {
  id: string
  label: string | null
  total: string
  guests: number | null
  opened_at: string
  waiter: string | null
  kitchen: TableKitchenState | null
}

export interface DiningTable {
  id: string
  area_id: string | null
  code: string
  name: string | null
  seats: number
  pos_x: number
  pos_y: number
  shape: 'square' | 'round' | 'rect'
  merged_into_id: string | null
  /** Libre, ocupada o absorbida por otra mesa. Se deduce, no se guarda. */
  state: 'free' | 'occupied' | 'merged'
  sale: TableSale | null
}

/** Cada cuánto se relee el salón. Cinco segundos: el mapa tiene que envejecer poco. */
const POLL_MS = 5_000

const areas = shallowRef<DiningArea[]>([])
const tables = shallowRef<DiningTable[]>([])
const polling = ref<ReturnType<typeof setInterval> | null>(null)

export function useDining() {
  const loading = ref(false)
  const saving = ref(false)

  const occupied = computed(() => tables.value.filter((table) => table.state === 'occupied'))

  /** Minutos desde que se abrió la cuenta: media lectura del mapa. */
  function elapsedMinutes(table: DiningTable): number | null {
    if (!table.sale) return null

    return Math.max(0, Math.round((Date.now() - new Date(table.sale.opened_at).getTime()) / 60_000))
  }

  async function refresh() {
    loading.value = true

    try {
      const data = await apiFetch<{ areas: DiningArea[], tables: DiningTable[] }>('/dining/map')
      areas.value = data.areas
      tables.value = data.tables
    } finally {
      loading.value = false
    }
  }

  function start() {
    if (polling.value) return

    void refresh().catch(() => undefined)
    // Un sondeo que falla no hace ruido: sin red el salón muestra lo último que
    // supo, que es más útil que una pantalla en blanco.
    polling.value = setInterval(() => void refresh().catch(() => undefined), POLL_MS)
  }

  function stop() {
    if (!polling.value) return

    clearInterval(polling.value)
    polling.value = null
  }

  /** Abre la cuenta: es una venta suspendida, no una tabla espejo. */
  async function openTable(tableId: string, guests?: number, label?: string) {
    saving.value = true

    try {
      const data = await apiFetch<{ sale: { id: string } }>(`/dining/tables/${tableId}/open`, {
        method: 'POST',
        body: JSON.stringify({ guests: guests ?? null, label: label?.trim() || null })
      })

      await refresh()

      return data.sale.id
    } finally {
      saving.value = false
    }
  }

  async function merge(mainId: string, tableIds: string[]) {
    saving.value = true

    try {
      await apiFetch(`/dining/tables/${mainId}/merge`, {
        method: 'POST',
        body: JSON.stringify({ tables: tableIds })
      })

      await refresh()
    } finally {
      saving.value = false
    }
  }

  async function split(mainId: string) {
    saving.value = true

    try {
      await apiFetch(`/dining/tables/${mainId}/split`, { method: 'POST' })
      await refresh()
    } finally {
      saving.value = false
    }
  }

  return { areas, tables, occupied, loading, saving, elapsedMinutes, refresh, start, stop, openTable, merge, split }
}
