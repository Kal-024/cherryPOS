import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

/**
 * El reintento es del módulo, no de la pantalla (H6.2).
 *
 * Estuvo colgado del ciclo de vida de la caja: un ticket cerrado sin red desde
 * el salón se quedaba en el equipo hasta que alguien abriera la pantalla de
 * venta. Nadie veía nada raro, y es dinero cobrado que el servidor no sabe que
 * existe.
 *
 * Lo que se prueba acá es **quién dispara el envío**, no IndexedDB ni el
 * formato del ticket: por eso el almacén es un `Map` y el sondeo un `ref`.
 */

const almacen = new Map<string, Map<string, unknown>>()

vi.mock('../db/idb', () => ({
  STORES: { products: 'products', usage: 'usage', carts: 'carts', meta: 'meta' },
  get: async (store: string, key: string) => almacen.get(store)?.get(key),
  put: async (store: string, key: string, value: unknown) => {
    if (!almacen.has(store)) almacen.set(store, new Map())
    almacen.get(store)!.set(key, value)
  },
  remove: async (store: string, key: string) => {
    almacen.get(store)?.delete(key)
  },
  all: async (store: string) => [...(almacen.get(store)?.values() ?? [])],
  entries: async (store: string) => [...(almacen.get(store)?.entries() ?? [])],
  clear: async (store: string) => almacen.get(store)?.clear(),
  putMany: async () => undefined,
  isAvailable: () => true
}))

/** Lo que el sondeo sabe y el navegador no: si el servidor **de la sucursal** contesta. */
const reachable = ref(true)

vi.mock('./useSync', () => ({
  useSync: () => ({ reachable })
}))

interface FalloApi { status: number, message: string }

/** Qué contesta el servidor en esta vuelta. */
let respuesta: 'ok' | 'sin_red' | FalloApi = 'ok'
const enviados: string[] = []

vi.mock('./httpClient', () => ({
  apiFetch: async (_path: string, init?: { body?: string }) => {
    if (respuesta === 'sin_red') throw new TypeError('Failed to fetch')
    if (respuesta !== 'ok') throw respuesta

    enviados.push(JSON.parse(init?.body ?? '{}').id as string)

    return { message: '', data: null, status: 'ok' }
  },
  isApiError: (err: unknown): err is FalloApi =>
    typeof err === 'object' && err !== null && 'status' in err
}))

const { useOffline } = await import('./useOffline')

const TERMINAL = '01a0-salon'

function activar() {
  localStorage.setItem('cherrypos.terminal.info', JSON.stringify({ id: TERMINAL, code: TERMINAL }))
}

function sembrarBootstrap() {
  if (!almacen.has('meta')) almacen.set('meta', new Map())

  almacen.get('meta')!.set(`t:${TERMINAL}:offline`, {
    terminal: { id: TERMINAL, code: TERMINAL, name: TERMINAL },
    branch: { id: 'suc', code: '001', name: 'Sucursal', timezone: 'UTC' },
    settings: {
      offline_max_hours: 24,
      fixed_quota_regime: false,
      tip_enabled: false,
      tip_suggested_percent: '10',
      cash_rounding_mode: 'none',
      cash_rounding_increment: '0.25',
      base_currency: 'NIO',
      secondary_currency: 'USD'
    },
    tax_codes: [],
    reservations: {
      counter: { range_from: 1, range_to: 100, next_number: 1, remaining: 100, template: '{SEQ:6}' }
    },
    cached_at: new Date().toISOString()
  })
}

async function venderSinRed() {
  return useOffline().closeOffline(
    '001',
    'empleado',
    [{ product_id: 'p1', description: 'Gaseosa', kind: 'product', qty: '1', unit_price: '45.00' }],
    [{ method: 'cash', amount: '45.00' }]
  )
}

/** Deja correr el watcher y las promesas que encadena. */
async function asentar(ms = 0) {
  await nextTick()
  await vi.advanceTimersByTimeAsync(ms)
}

beforeEach(async () => {
  vi.useFakeTimers()
  almacen.clear()
  localStorage.clear()
  enviados.length = 0
  respuesta = 'ok'
  reachable.value = true

  activar()
  sembrarBootstrap()
  await useOffline().restore()
})

afterEach(() => {
  useOffline().stopAutoFlush()
  vi.useRealTimers()
  localStorage.clear()
})

describe('el reintento no depende de la pantalla de venta', () => {
  it('manda lo pendiente cuando vuelve el servidor, sin abrir la caja', async () => {
    const offline = useOffline()

    // El salón cierra un ticket con el servidor caído.
    respuesta = 'sin_red'
    reachable.value = false
    offline.startAutoFlush()

    const ticket = await venderSinRed()
    await asentar(10_000)

    expect(offline.pending.value).toHaveLength(1)
    expect(enviados).toEqual([])

    // Vuelve el servidor. Nadie tocó nada: el sondeo avisa y la bandeja sale
    // sola, desde cualquier pantalla.
    respuesta = 'ok'
    reachable.value = true
    await asentar()

    expect(enviados).toEqual([ticket.id])
    expect(offline.pending.value).toHaveLength(0)
  })

  it('reintenta con retroceso mientras el servidor no vuelve', async () => {
    const offline = useOffline()

    respuesta = 'sin_red'
    offline.startAutoFlush()
    await venderSinRed()
    await asentar()

    // Primer retroceso: cinco segundos. Antes de eso no se vuelve a intentar.
    respuesta = 'ok'
    await asentar(4_000)
    expect(enviados).toEqual([])

    await asentar(2_000)
    expect(enviados).toHaveLength(1)
    expect(offline.pending.value).toHaveLength(0)
  })

  it('al arrancar sale lo que quedó de ayer', async () => {
    const offline = useOffline()

    // El equipo se apagó con la bandeja llena y nadie volvió a tocarlo.
    respuesta = 'sin_red'
    const ticket = await venderSinRed()

    respuesta = 'ok'
    offline.startAutoFlush()
    await asentar()

    expect(enviados).toEqual([ticket.id])
  })
})

describe('no todo fallo es culpa del ticket', () => {
  it('la sesión vencida no manda el ticket a la bandeja del supervisor', async () => {
    const offline = useOffline()

    // La credencial de terminal dura un día: la primera pantalla de cada mañana
    // la encuentra vencida. Marcar el ticket sería mandar a revisar algo que se
    // arregla volviendo a identificarse.
    respuesta = { status: 401, message: 'Unauthenticated.' }
    offline.startAutoFlush()
    await venderSinRed()
    await asentar(6_000)

    expect(offline.pending.value[0]?.last_error).toBeNull()
    expect(offline.pending.value[0]?.attempts).toBe(0)

    // Reactivada la terminal, el mismo ticket sale sin intervención. El
    // retroceso ya va por su segundo escalón: quince segundos, no cinco.
    respuesta = 'ok'
    await asentar(20_000)

    expect(enviados).toHaveLength(1)
  })

  it('un ticket rechazado se anota y deja de consumir vueltas', async () => {
    const offline = useOffline()

    respuesta = { status: 422, message: 'El turno está cerrado.' }
    offline.startAutoFlush()
    await venderSinRed()
    await asentar(6_000)

    expect(offline.pending.value[0]?.last_error).toBe('El turno está cerrado.')
    expect(offline.pending.value[0]?.attempts).toBe(1)

    // Aunque el servidor vuelva a estar bien, no se reintenta: insistir contra
    // un ticket que nunca va a pasar retrasa los que sí.
    respuesta = 'ok'
    reachable.value = false
    reachable.value = true
    await asentar(120_000)

    expect(enviados).toEqual([])
    expect(offline.pending.value[0]?.attempts).toBe(1)
  })
})
