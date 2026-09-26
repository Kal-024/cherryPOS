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
  /** La libreta del mesero sobre esta cuenta. Se va con ella al cobrar. */
  notes: string | null
  /** Cuándo se imprimió la precuenta. Nulo: todavía no la pidieron. */
  bill_requested_at: string | null
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
  /** Tamaño en el plano, en píxeles. Se configura en la trastienda. */
  width: number
  height: number
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

  /**
   * Cuánto lleva la mesa esperando pagar (F1-B).
   *
   * «Pidió la cuenta hace quince minutos» es un problema que hay que atender;
   * «pidió la cuenta» no dice nada. Por eso se guarda la hora y no una bandera.
   */
  function waitingToPayMinutes(table: DiningTable): number | null {
    if (!table.sale?.bill_requested_at) return null

    return Math.max(
      0,
      Math.round((Date.now() - new Date(table.sale.bill_requested_at).getTime()) / 60_000)
    )
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

  /**
   * Suelta mesas del grupo.
   *
   * **Qué se suelta depende de cuál mesa se manda.** Una unida se suelta sola y
   * el resto del grupo sigue junto; la principal deshace el grupo entero. Antes
   * solo existía lo segundo, y devolver una mesa a su sitio obligaba a rearmar
   * a mano lo que nadie quiso deshacer.
   */
  async function split(tableId: string) {
    saving.value = true

    try {
      await apiFetch(`/dining/tables/${tableId}/split`, { method: 'POST' })
      await refresh()
    } finally {
      saving.value = false
    }
  }

  /** Anota algo sobre la cuenta. Vacío la borra: no hace falta un segundo botón. */
  async function setNote(tableId: string, notes: string) {
    saving.value = true

    try {
      await apiFetch(`/dining/tables/${tableId}/note`, {
        method: 'PUT',
        body: JSON.stringify({ notes })
      })

      await refresh()
    } finally {
      saving.value = false
    }
  }

  /**
   * Configurar el salón (`dining.manage`).
   *
   * Estas cuatro llamadas existían en el servidor desde el primer día y **nadie
   * las usaba**: el plano se sembraba a mano en la base y el texto de la
   * pantalla prometía una administración que no estaba construida. Mover una
   * mesa es editarla — la posición es un campo más, no una operación aparte.
   */
  async function createArea(area: { code: string, name: string, sort_order?: number }) {
    saving.value = true

    try {
      await apiFetch('/dining/areas', { method: 'POST', body: JSON.stringify(area) })
      await refresh()
    } finally {
      saving.value = false
    }
  }

  async function createTable(table: Partial<DiningTable> & { code: string }) {
    saving.value = true

    try {
      await apiFetch('/dining/tables', { method: 'POST', body: JSON.stringify(table) })
      await refresh()
    } finally {
      saving.value = false
    }
  }

  /**
   * Guarda los cambios de una mesa, incluida su posición.
   *
   * Se llama **al soltar**, no mientras se arrastra: un `PUT` por píxel
   * recorrido llenaría la bitácora de ruido y la red de viajes inútiles.
   */
  async function updateTable(id: string, changes: Partial<DiningTable>) {
    saving.value = true

    try {
      await apiFetch(`/dining/tables/${id}`, { method: 'PUT', body: JSON.stringify(changes) })
    } finally {
      saving.value = false
    }
  }

  /** Baja lógica: las ventas de ayer la referencian y el histórico no se toca. */
  async function removeTable(id: string) {
    saving.value = true

    try {
      await apiFetch(`/dining/tables/${id}`, { method: 'DELETE' })
      await refresh()
    } finally {
      saving.value = false
    }
  }

  /** Mueve la mesa en memoria, para que el arrastre se vea sin esperar al servidor. */
  function placeLocally(id: string, x: number, y: number) {
    tables.value = tables.value.map(
      (table) => (table.id === id ? { ...table, pos_x: x, pos_y: y } : table)
    )
  }

  /** Lo mismo al redimensionar: la esquina tiene que seguir al dedo. */
  function resizeLocally(id: string, width: number, height: number) {
    tables.value = tables.value.map(
      (table) => (table.id === id ? { ...table, width, height } : table)
    )
  }

  return {
    areas,
    tables,
    occupied,
    loading,
    saving,
    elapsedMinutes,
    waitingToPayMinutes,
    refresh,
    start,
    stop,
    openTable,
    merge,
    split,
    setNote,
    createArea,
    createTable,
    updateTable,
    removeTable,
    placeLocally,
    resizeLocally
  }
}
