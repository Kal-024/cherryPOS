import { computed, effectScope, ref, shallowRef, watch, type EffectScope } from 'vue'
import { calculateSale, type SaleInput, type SaleResult, type TaxRate } from '@cherrypos/calc'
import { apiFetch, isApiError } from './httpClient'
import { useSync } from './useSync'
import { STORES, entries, get, put, remove } from '../db/idb'
import { activeTerminalId, belongsTo, ownerOf, scoped, terminalPrefix } from '../db/scope'
import { uuid7 } from '../utils/uuid'

/**
 * Modo degradado (H6).
 *
 * **Acá es donde el motor de cálculo en TypeScript deja de ser un espejo.**
 * Existe duplicado —PHP en el servidor, TypeScript acá— justamente para este
 * momento: con el servidor apagado, la caja tiene que poder totalizar, cobrar y
 * entregar un ticket. Los fixtures compartidos son lo que garantiza que las dos
 * implementaciones den lo mismo al centavo; si divergen, el servidor lo detecta
 * al recibir y avisa.
 *
 * **El alcance es acotado a propósito.** Sin servidor no hay stock en tiempo
 * real de otras cajas, venta a crédito, cierre de turno, KDS, autorización de
 * supervisor ni consulta de historial. Prometer paridad sería prometer lo que no
 * se puede sostener.
 *
 * Tres piezas lo sostienen:
 *
 *  1. **El arranque** (`bootstrap`) baja lo que hará falta: configuración,
 *     códigos de impuesto y el bloque de correlativos. Lo que no se bajó antes
 *     del corte no se va a poder bajar durante.
 *  2. **La bandeja de salida** guarda los tickets en IndexedDB, así que
 *     sobreviven a que se cierre el navegador o se apague el monitor.
 *  3. **El vencimiento**: pasadas las horas que declara el ERP, la caja deja de
 *     vender. Un ticket muy viejo choca con el cierre de período y con precios
 *     que ya cambiaron.
 */

export interface OfflineSettings {
  offline_max_hours: number
  fixed_quota_regime: boolean
  /** Propina (G-16): apagada por defecto, se enciende por negocio. */
  tip_enabled: boolean
  tip_suggested_percent: string
  cash_rounding_mode: 'none' | 'nearest' | 'up' | 'down'
  cash_rounding_increment: string
  base_currency: string
  secondary_currency: string
}

export interface OfflineTaxCode {
  id: string
  code: string
  rate: string
  type: 'vat' | 'exempt'
  base: 'net' | 'gross'
}

export interface OfflineReservation {
  range_from: number
  range_to: number
  next_number: number
  remaining: number
  template: string
}

interface Bootstrap {
  terminal: { id: string, code: string, name: string }
  branch: { id: string, code: string, name: string, timezone: string }
  settings: OfflineSettings
  tax_codes: OfflineTaxCode[]
  reservations: Record<string, OfflineReservation>
}

/** Una línea tal como la arma el terminal antes de que exista la venta. */
export interface DraftLine {
  product_id: string | null
  description: string
  kind: 'product' | 'temporary' | 'amount'
  qty: string
  unit_price: string
  tax_code_id?: string | null
  is_exempt?: boolean
  discount_type?: 'percent' | 'amount' | null
  discount_value?: string | null
}

export interface DraftPayment {
  method: 'cash' | 'card' | 'transfer' | 'other'
  amount: string
  currency_code?: string
  exchange_rate?: string | null
}

export interface QueuedTicket {
  id: string
  number: string
  sale_type: string
  employee_id: string
  currency_code: string
  opened_at: string
  closed_at: string
  lines: DraftLine[]
  payments: DraftPayment[]
  /** Propina cobrada con el ticket. Fuera de `totals` porque no es fiscal (G-16). */
  tip_amount?: string
  totals: { total: string, tax_total: string }
  attempts: number
  last_error: string | null
}

const settings = shallowRef<OfflineSettings | null>(null)
const taxCodes = shallowRef<Map<string, OfflineTaxCode>>(new Map())
const reservation = ref<OfflineReservation | null>(null)
const pending = ref<QueuedTicket[]>([])
const flushing = ref(false)
const bootstrappedAt = ref<string | null>(null)

/**
 * Lo guardado lleva el dueño en la clave.
 *
 * `t:<terminal>:offline` y `t:<terminal>:outbox:<ticket>`. Sin eso, la caja del
 * salón adoptaba la bandeja del mostrador —tickets con **correlativos de otra
 * caja**— y su bloque de números reservados, que es la vía más corta a dos
 * tickets con el mismo número.
 */
const BOOTSTRAP_KEY = 'offline'
const OUTBOX_PREFIX = 'outbox:'

/**
 * Objeto plano, sin proxies de Vue.
 *
 * IndexedDB guarda con el algoritmo de clonado estructurado, que **no sabe
 * clonar un Proxy**: pasarle un arreglo reactivo termina en `DataCloneError` y
 * el ticket no se guarda. Con red eso sería un aviso; sin red es la venta que se
 * acaba de cobrar.
 */
function plain<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T
}

function bootstrapKey(): string {
  return scoped(BOOTSTRAP_KEY)
}

function outboxKey(ticketId: string): string {
  return scoped(OUTBOX_PREFIX + ticketId)
}

async function loadFromDisk() {
  const terminal = activeTerminalId()

  const [cached, queued] = await Promise.all([
    get<Bootstrap & { cached_at: string }>(STORES.meta, bootstrapKey()),
    entries<QueuedTicket>(STORES.carts)
  ])

  settings.value = cached?.settings ?? null
  taxCodes.value = new Map((cached?.tax_codes ?? []).map((tax) => [tax.id, tax]))
  reservation.value = cached?.reservations?.counter ?? null
  bootstrappedAt.value = cached?.cached_at ?? null

  // Solo la bandeja de **esta** terminal. Lo de las otras sigue en disco,
  // esperando a que se las active: es lo que permite cambiar de caja sin dejar
  // tickets huérfanos.
  pending.value = queued
    .filter(([key]) => key.includes(OUTBOX_PREFIX) && (terminal === null || belongsTo(key, terminal)))
    .map(([, ticket]) => ticket)
}

/**
 * Manda lo pendiente.
 *
 * El 200 del duplicado es éxito: el terminal reintenta por diseño y el servidor
 * responde lo mismo que la primera vez.
 *
 * **Tres desenlaces, y conviene no confundirlos.** Un 422 es del ticket: no va a
 * pasar nunca, se anota y se deja para el supervisor. Un 401, un 423 o un 429 no
 * son del ticket sino de la sesión o del ritmo —la credencial de terminal dura un
 * día y vence cada mañana—, así que se corta la vuelta sin tocarlo: marcarlo
 * mandaría a la bandeja algo que se arregla volviendo a identificarse. Un fallo
 * de red corta igual y se reintenta después.
 *
 * Los ya rechazados no se vuelven a mandar en cada vuelta: insistir contra un
 * ticket que nunca va a pasar retrasa los que sí, y con el reintento automático
 * encendido sería una petición fallida cada minuto para siempre.
 */
async function flushQueue(): Promise<{ sent: number, failed: number }> {
  if (flushing.value || pending.value.length === 0) return { sent: 0, failed: 0 }

  flushing.value = true
  let sent = 0
  let failed = 0

  try {
    for (const ticket of [...pending.value]) {
      if (ticket.last_error !== null) continue

      try {
        await apiFetch('/offline-sales', {
          method: 'POST',
          body: JSON.stringify(ticket)
        })

        await remove(STORES.carts, outboxKey(ticket.id))
        pending.value = pending.value.filter((entry) => entry.id !== ticket.id)
        sent++
      } catch (err) {
        failed++

        // La sesión venció, la terminal quedó bloqueada o el servidor pide bajar
        // el ritmo. No es culpa del ticket: se espera y se vuelve.
        if (isApiError(err) && (err.status === 401 || err.status === 423 || err.status === 429)) break

        if (isApiError(err) && err.status >= 400 && err.status < 500) {
          // Va a la bandeja de excepciones del supervisor: reintentar no lo
          // arregla y frena la cola.
          ticket.last_error = err.message
          ticket.attempts += 1
          await put(STORES.carts, outboxKey(ticket.id), ticket)

          continue
        }

        // Red caída: se corta la vuelta y se reintenta después.
        break
      }
    }
  } finally {
    flushing.value = false
  }

  return { sent, failed }
}

/**
 * El reintento es del módulo, no de la pantalla (H6.2).
 *
 * Estuvo colgado del ciclo de vida de la caja, y eso dejaba un agujero con forma
 * de restaurante: el mesero cierra un ticket sin red desde el salón, se va a otra
 * mesa, y ese dinero no sale del equipo hasta que alguien abra la pantalla de
 * venta. Nadie ve nada raro — la caja de al lado factura normal.
 *
 * Quien avisa es el sondeo: es el único que sabe si el servidor **de la
 * sucursal** contesta, porque la red del navegador puede estar perfecta y el
 * equipo del fondo apagado. Encima va un reintento propio con retroceso, para lo
 * que el sondeo no cubre: la aplicación que arranca con tickets de ayer, o el
 * envío que se cortó a la mitad sin que la conexión llegara a caerse.
 */
const RETRY_MS = [5000, 15000, 30000, 60000]

let autoScope: EffectScope | null = null
let retryTimer: ReturnType<typeof setTimeout> | null = null
let retryFailures = 0

function cancelRetry(): void {
  if (retryTimer !== null) {
    clearTimeout(retryTimer)
    retryTimer = null
  }
}

function scheduleRetry(): void {
  if (retryTimer !== null) return

  const delay = RETRY_MS[Math.min(retryFailures, RETRY_MS.length - 1)] ?? 60000
  retryFailures += 1

  retryTimer = setTimeout(() => {
    retryTimer = null
    void attemptFlush()
  }, delay)
}

/** Una vuelta: si quedó algo enviable, se agenda la siguiente. */
async function attemptFlush(): Promise<void> {
  cancelRetry()

  const enviables = () => pending.value.some((ticket) => ticket.last_error === null)

  if (!enviables()) {
    retryFailures = 0

    return
  }

  await flushQueue()

  if (enviables()) {
    scheduleRetry()
  } else {
    retryFailures = 0
  }
}

/**
 * Enciende el reintento automático. Idempotente: lo llaman todas las pantallas
 * que pueden tener tickets esperando, y solo la primera lo arranca.
 */
function startAutoFlush(): void {
  if (autoScope !== null) return

  // Scope propio y desprendido: el reintento vive mientras viva la aplicación,
  // no mientras viva el componente que lo encendió — que es justo el error que
  // se está corrigiendo.
  autoScope = effectScope(true)

  autoScope.run(() => {
    const { reachable } = useSync()

    watch(reachable, (up) => {
      if (up) void attemptFlush()
    })
  })

  // Al arrancar puede haber quedado algo de ayer: el equipo se apagó con la
  // bandeja llena y nadie tocó nada desde entonces.
  void attemptFlush()
}

/** Se apaga al cambiar de terminal o cerrar sesión: lo guardado es de su dueño. */
function stopAutoFlush(): void {
  autoScope?.stop()
  autoScope = null
  cancelRetry()
  retryFailures = 0
}

export function useOffline() {
  const ready = computed(() => settings.value !== null && reservation.value !== null)

  /**
   * ¿Quedan números y tiempo para seguir vendiendo sin servidor?
   *
   * Las dos condiciones son duras: sin correlativos no se puede numerar, y
   * pasada la ventana el ticket ya no lo acepta el servidor (H6.5).
   */
  const canSellOffline = computed(() => {
    if (!ready.value || !reservation.value) return false
    if (reservation.value.next_number > reservation.value.range_to) return false

    return hoursSinceBootstrap() < (settings.value?.offline_max_hours ?? 0)
  })

  const remainingNumbers = computed(() =>
    reservation.value ? reservation.value.range_to - reservation.value.next_number + 1 : 0
  )

  function hoursSinceBootstrap(): number {
    if (!bootstrappedAt.value) return Number.POSITIVE_INFINITY

    return (Date.now() - new Date(bootstrappedAt.value).getTime()) / 3_600_000
  }

  async function restore() {
    await loadFromDisk()
  }

  /**
   * Qué quedó sin enviar en este equipo, y de quién es (H6.4).
   *
   * Un ticket vendido sin conexión **solo lo puede mandar su propia terminal**:
   * el servidor lo atribuye según el token y su número sale de la serie
   * reservada a esa caja. Guardarlo con dueño hace que sobreviva al cambio; que
   * salga depende de que alguien vuelva a activar esa caja, y para eso hay que
   * poder verlo desde la pantalla de activación.
   *
   * @return terminales con pendientes, la propia incluida
   */
  async function pendingByTerminal(): Promise<Array<{ terminal_id: string, count: number }>> {
    const queued = await entries<QueuedTicket>(STORES.carts)
    const counts = new Map<string, number>()

    for (const [key] of queued) {
      if (!key.includes(OUTBOX_PREFIX)) continue

      // Sin dueño en la clave es de una versión anterior: se cuenta aparte para
      // que no desaparezca de la vista.
      const owner = ownerOf(key) ?? 'sin-terminal'
      counts.set(owner, (counts.get(owner) ?? 0) + 1)
    }

    return [...counts.entries()].map(([terminal_id, count]) => ({ terminal_id, count }))
  }

  /**
   * Le pone dueño a lo que quedó de una versión anterior.
   *
   * Corre al activar una terminal. Antes de que el estado tuviera dueño, la
   * bandeja y la reserva se guardaban con claves sueltas; descartarlas sería
   * tirar tickets que representan plata ya cobrada, así que se adoptan bajo la
   * terminal que se acaba de activar — que en un equipo que venía funcionando es
   * la única que hubo.
   */
  async function adoptLegacy(terminalId: string): Promise<number> {
    const [queued, meta] = await Promise.all([
      entries<QueuedTicket>(STORES.carts),
      entries<unknown>(STORES.meta)
    ])

    let adopted = 0

    for (const [key, value] of queued) {
      if (ownerOf(key) !== null) continue
      if (!key.startsWith(OUTBOX_PREFIX) && key !== 'current') continue

      await put(STORES.carts, terminalPrefix(terminalId) + key, value)
      await remove(STORES.carts, key)
      adopted++
    }

    for (const [key, value] of meta) {
      if (key !== BOOTSTRAP_KEY) continue

      await put(STORES.meta, terminalPrefix(terminalId) + key, value)
      await remove(STORES.meta, key)
    }

    if (adopted > 0) await loadFromDisk()

    return adopted
  }

  /**
   * Baja lo que hará falta y aparta correlativos.
   *
   * Se llama al abrir la caja, con red. Lo que no se bajó antes del corte no se
   * va a poder bajar durante: ese es todo el punto.
   */
  async function bootstrap(reserveSize = 100): Promise<boolean> {
    try {
      let data = await apiFetch<Bootstrap>('/terminal/bootstrap')

      // Si no hay bloque o queda poco, se pide otro ahora que hay red.
      const current = data.reservations?.counter

      if (!current || current.remaining < 20) {
        await apiFetch('/terminal/sequence/reserve', {
          method: 'POST',
          body: JSON.stringify({ document_type: 'counter', size: reserveSize })
        })

        data = await apiFetch<Bootstrap>('/terminal/bootstrap')
      }

      settings.value = data.settings
      taxCodes.value = new Map(data.tax_codes.map((tax) => [tax.id, tax]))
      reservation.value = data.reservations?.counter ?? null
      bootstrappedAt.value = new Date().toISOString()

      await put(STORES.meta, bootstrapKey(), { ...data, cached_at: bootstrappedAt.value })

      return true
    } catch {
      // Sin red se sigue con lo que haya en disco. Es exactamente el caso que
      // el modo degradado tiene que cubrir.
      await loadFromDisk()

      return false
    }
  }

  /** El siguiente número del bloque, ya formateado con la plantilla de la serie. */
  function nextNumber(branchCode: string): string | null {
    if (!reservation.value || reservation.value.next_number > reservation.value.range_to) {
      return null
    }

    const sequence = reservation.value.next_number
    const year = new Date().getFullYear()

    return reservation.value.template
      .replace('{BRANCH}', branchCode)
      .replace('{TYPE}', 'COU')
      .replace('{YEAR}', String(year))
      .replace('{YY}', String(year).slice(-2))
      .replace(/\{SEQ(?::(\d+))?\}/, (_, width?: string) =>
        width ? String(sequence).padStart(Number(width), '0') : String(sequence)
      )
  }

  /**
   * Los totales, calculados acá.
   *
   * Es el motor de `packages/calc`, el mismo que verifica el CI contra su
   * gemelo en PHP. Ni una fórmula vive en esta pantalla.
   */
  function calculate(lines: DraftLine[], payments: DraftPayment[], customerExempt = false, tip = '0'): SaleResult {
    const input: SaleInput = {
      config: {
        currency: settings.value?.base_currency ?? 'NIO',
        fixedQuotaRegime: settings.value?.fixed_quota_regime ?? false,
        cashRounding: {
          mode: settings.value?.cash_rounding_mode ?? 'none',
          increment: settings.value?.cash_rounding_increment ?? '0.25'
        }
      },
      customer: { taxExempt: customerExempt },
      lines: lines.map((line, index) => ({
        id: String(index),
        qty: line.qty,
        unitPrice: line.unit_price,
        ...(line.discount_type
          ? { discount: { type: line.discount_type, value: line.discount_value ?? '0' } }
          : {}),
        taxes: taxesFor(line),
        exempt: line.is_exempt ?? false
      })),
      // La propina se cobra sin servidor igual que con él: sale del mismo motor
      // y por la misma razón que el resto de los importes (G-16).
      tip,
      payments: payments.map((payment) => ({
        method: payment.method,
        amount: payment.amount,
        ...(payment.currency_code ? { currency: payment.currency_code } : {}),
        ...(payment.exchange_rate ? { rate: payment.exchange_rate } : {})
      }))
    }

    return calculateSale(input)
  }

  function taxesFor(line: DraftLine): TaxRate[] {
    const tax = line.tax_code_id ? taxCodes.value.get(line.tax_code_id) : undefined

    if (!tax || tax.type === 'exempt') return []

    return [{ code: tax.code, rate: tax.rate, base: tax.base }]
  }

  /**
   * Cierra el ticket sin servidor y lo deja en la bandeja de salida.
   *
   * El identificador y el número salen del terminal. A partir de acá el ticket
   * es un hecho consumado: el dinero está en el cajón y el servidor, cuando
   * vuelva, lo registra — no lo aprueba.
   */
  async function closeOffline(
    branchCode: string,
    employeeId: string,
    lines: DraftLine[],
    payments: DraftPayment[],
    tip = '0.00'
  ): Promise<QueuedTicket> {
    const number = nextNumber(branchCode)

    if (!number || !canSellOffline.value) {
      throw new Error('offline.cannot_sell')
    }

    const result = calculate(lines, payments, false, tip)
    const now = new Date().toISOString()

    // **Copia plana antes de guardar.** Las líneas y los pagos llegan desde el
    // carrito, que es reactivo, y un proxy de Vue no se puede clonar: IndexedDB
    // lo rechaza con `DataCloneError` y el ticket se pierde justo cuando no hay
    // servidor que lo salve. Es el único lugar donde lo guardado viene de la
    // pantalla en vez de la API.
    const ticket: QueuedTicket = plain({
      id: uuid7(),
      number,
      sale_type: 'counter',
      employee_id: employeeId,
      currency_code: settings.value?.base_currency ?? 'NIO',
      opened_at: now,
      closed_at: now,
      lines,
      payments,
      // Viaja aparte de los totales a propósito: el servidor la vuelve a poner
      // fuera del total al recibir, y si el terminal la hubiera sumado adentro,
      // la diferencia lo delata.
      tip_amount: result.tip,
      totals: { total: result.total, tax_total: result.taxTotal },
      attempts: 0,
      last_error: null
    })

    await put(STORES.carts, outboxKey(ticket.id), ticket)
    pending.value = [...pending.value, ticket]

    // Queda agendado desde ya. Sin red el intento falla y retrocede, que es lo
    // correcto: lo que no puede pasar es que el ticket espere a que alguien
    // vuelva a abrir la caja.
    scheduleRetry()

    // El número se consume localmente: si el navegador se cierra antes de
    // sincronizar, el siguiente ticket no puede repetirlo.
    if (reservation.value) {
      reservation.value = { ...reservation.value, next_number: reservation.value.next_number + 1 }
      const cached = await get<Bootstrap & { cached_at: string }>(STORES.meta, bootstrapKey())

      if (cached) {
        cached.reservations.counter = reservation.value
        await put(STORES.meta, bootstrapKey(), cached)
      }
    }

    return ticket
  }

  return {
    settings,
    reservation,
    pending,
    flushing,
    ready,
    canSellOffline,
    remainingNumbers,
    hoursSinceBootstrap,
    restore,
    bootstrap,
    nextNumber,
    calculate,
    pendingByTerminal,
    adoptLegacy,
    closeOffline,
    flush: flushQueue,
    startAutoFlush,
    stopAutoFlush
  }
}
