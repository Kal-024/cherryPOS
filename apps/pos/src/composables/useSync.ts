import { onScopeDispose, ref, shallowRef } from 'vue'
import { apiFetch, isApiError } from './httpClient'

/**
 * Sincronización entre terminales por consulta periódica (G-13, R-01).
 *
 * El plan dejaba la tecnología de tiempo real *"por definir"*. Se resolvió a
 * favor del sondeo y no de WebSockets: todo ocurre dentro de la red local del
 * negocio, donde la diferencia entre un aviso instantáneo y uno de dos segundos
 * no la percibe nadie, y un servidor de conexiones persistentes sería una pieza
 * más que instalar y vigilar en el equipo de cada cliente — donde no hay nadie
 * de sistemas.
 *
 * El sondeo además se degrada bien: si la caja se queda sin red, reintenta y al
 * volver se pone al día sola con su último cursor. Una conexión persistente
 * caída hay que detectarla y reabrirla, y ahí es donde viven los errores raros.
 *
 * **Se encadena, no se agenda.** El siguiente sondeo se programa cuando el
 * anterior terminó: con `setInterval`, una respuesta lenta acumula peticiones
 * que se pisan entre sí, y una caja en hora pico es exactamente cuando eso pasa.
 */

export interface SyncSale {
  id: string
  status: 'draft' | 'suspended' | 'completed' | 'voided'
  label: string | null
  sale_type: string
  total: string
  terminal_id: string
  employee_id: string
}

export interface SyncNotification {
  id: string
  event: string
  severity: 'info' | 'warning' | 'critical'
  title: string
  body: string | null
  occurred_at: string
}

export interface SyncShift {
  id: string
  code: string
  opened_at: string
  exchange_rate: string | null
}

export interface SyncCatalogWatermark {
  products_updated_at: string | null
  tax_codes_updated_at: string | null
  exchange_rate_updated_at: string | null
}

export interface SyncChanges {
  sales: SyncSale[]
  notifications: SyncNotification[]
  shift: SyncShift | null
  catalog: SyncCatalogWatermark
}

interface SyncResponse {
  cursor: string
  poll_after_seconds: number
  changes: SyncChanges
}

/** Cuánto esperar tras un fallo, con techo. Reintentar cada dos segundos contra
 *  un servidor caído solo llena el registro. */
const BACKOFF_MS = [2000, 5000, 15000, 30000, 60000]

const cursor = ref<string | null>(null)
const running = ref(false)
const reachable = ref(true)

/** Cuentas abiertas de toda la sucursal, no solo de esta caja. */
const suspendedSales = shallowRef<Map<string, SyncSale>>(new Map())
const notifications = shallowRef<SyncNotification[]>([])
const shift = shallowRef<SyncShift | null>(null)
const catalog = shallowRef<SyncCatalogWatermark | null>(null)

let timer: ReturnType<typeof setTimeout> | null = null
let failures = 0

function applySales(sales: SyncSale[]) {
  if (sales.length === 0) return

  const next = new Map(suspendedSales.value)

  for (const sale of sales) {
    // Una venta que dejó de estar suspendida se saca de la lista. Mandar solo
    // las suspendidas dejaría cuentas fantasma en pantalla, que es el error que
    // un restaurante no perdona.
    if (sale.status === 'suspended') {
      next.set(sale.id, sale)
    } else {
      next.delete(sale.id)
    }
  }

  suspendedSales.value = next
}

async function tick(): Promise<number> {
  const query = cursor.value ? `?since=${encodeURIComponent(cursor.value)}` : ''
  const data = await apiFetch<SyncResponse>(`/sync/changes${query}`)

  cursor.value = data.cursor
  applySales(data.changes.sales)

  if (data.changes.notifications.length > 0) {
    notifications.value = [...data.changes.notifications, ...notifications.value].slice(0, 50)
  }

  // El turno viaja siempre: evita que la caja siga vendiendo contra un turno
  // que el supervisor cerró desde otra pantalla.
  shift.value = data.changes.shift
  catalog.value = data.changes.catalog

  return data.poll_after_seconds * 1000
}

async function loop() {
  if (!running.value) return

  let delay: number

  try {
    delay = await tick()
    failures = 0
    reachable.value = true
  } catch (err) {
    // Un 401 o 423 no es un problema de red: la sesión cambió, y seguir
    // sondeando no la arregla.
    if (isApiError(err) && (err.status === 401 || err.status === 423)) {
      stop()
      return
    }

    reachable.value = false
    delay = BACKOFF_MS[Math.min(failures, BACKOFF_MS.length - 1)] ?? 60000
    failures += 1
  }

  if (running.value) {
    timer = setTimeout(loop, delay)
  }
}

function start() {
  if (running.value) return

  running.value = true
  void loop()
}

function stop() {
  running.value = false

  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
}

/** Vuelve a empezar desde cero. Se usa al cambiar de cajero o de terminal. */
function reset() {
  stop()
  cursor.value = null
  suspendedSales.value = new Map()
  notifications.value = []
  shift.value = null
  failures = 0
}

export function useSync() {
  onScopeDispose(() => {
    // El sondeo es de la aplicación, no de un componente: desmontar una
    // pantalla no debe cortarlo. Solo se detiene explícitamente.
  })

  return {
    cursor,
    running,
    reachable,
    suspendedSales,
    notifications,
    shift,
    catalog,
    start,
    stop,
    reset
  }
}
