import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Estado sin conexión, con dueño (H6, D-05).
 *
 * Un mismo equipo puede ser el mostrador un rato y el salón otro. Lo que se
 * guarda en disco no es del navegador: **es de la terminal**. Los tickets
 * vendidos sin conexión llevan correlativos reservados a esa caja y el servidor
 * los atribuye según el token con el que se envían, así que confundir de quién
 * son no es un detalle de orden — es emitir dos tickets con el mismo número.
 *
 * El almacén se reemplaza por un `Map` porque lo que se prueba es **de quién es
 * cada clave**, no IndexedDB.
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

const { useOffline } = await import('./useOffline')

const MOSTRADOR = '01a0-mostrador'
const SALON = '01a0-salon'

function activar(terminalId: string) {
  localStorage.setItem('cherrypos.terminal.info', JSON.stringify({ id: terminalId, code: terminalId }))
}

/** Lo que deja el arranque con red: configuración, impuestos y correlativos. */
function sembrarBootstrap(terminalId: string, desde = 1) {
  if (!almacen.has('meta')) almacen.set('meta', new Map())

  almacen.get('meta')!.set(`t:${terminalId}:offline`, {
    terminal: { id: terminalId, code: terminalId, name: terminalId },
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
      counter: { range_from: desde, range_to: desde + 99, next_number: desde, remaining: 100, template: '{SEQ:6}' }
    },
    cached_at: new Date().toISOString()
  })
}

async function venderSinRed() {
  const offline = useOffline()
  await offline.restore()

  return offline.closeOffline(
    '001',
    'empleado',
    [{ product_id: 'p1', description: 'Gaseosa', kind: 'product', qty: '1', unit_price: '45.00' }],
    [{ method: 'cash', amount: '45.00' }]
  )
}

beforeEach(() => {
  almacen.clear()
  localStorage.clear()
})

afterEach(() => {
  localStorage.clear()
})

describe('lo guardado tiene dueño', () => {
  it('el ticket sin enviar queda bajo su terminal', async () => {
    activar(MOSTRADOR)
    sembrarBootstrap(MOSTRADOR)

    const ticket = await venderSinRed()

    expect([...almacen.get('carts')!.keys()]).toEqual([`t:${MOSTRADOR}:outbox:${ticket.id}`])
  })

  it('el salón no adopta la bandeja del mostrador', async () => {
    activar(MOSTRADOR)
    sembrarBootstrap(MOSTRADOR)
    await venderSinRed()

    // Cambio de terminal en el mismo equipo: lo guardado no se borra.
    activar(SALON)
    sembrarBootstrap(SALON, 500)

    const offline = useOffline()
    await offline.restore()

    // Un ticket ajeno en la bandeja significaría intentar enviarlo con el token
    // de otra caja y con un número que no es de su serie.
    expect(offline.pending.value).toHaveLength(0)
    expect(offline.reservation.value?.next_number).toBe(500)
  })

  it('al volver a la terminal, lo pendiente sigue ahí', async () => {
    activar(MOSTRADOR)
    sembrarBootstrap(MOSTRADOR)
    const ticket = await venderSinRed()

    activar(SALON)
    sembrarBootstrap(SALON, 500)
    await useOffline().restore()

    activar(MOSTRADOR)
    const offline = useOffline()
    await offline.restore()

    expect(offline.pending.value.map((entry) => entry.id)).toEqual([ticket.id])
  })

  it('el listado dice qué quedó esperando en el equipo y de quién es', async () => {
    activar(MOSTRADOR)
    sembrarBootstrap(MOSTRADOR)
    await venderSinRed()

    activar(SALON)
    sembrarBootstrap(SALON, 500)
    await venderSinRed()

    const resumen = await useOffline().pendingByTerminal()

    expect(resumen).toEqual(
      expect.arrayContaining([
        { terminal_id: MOSTRADOR, count: 1 },
        { terminal_id: SALON, count: 1 }
      ])
    )
  })

  it('lo que quedó de antes se adopta en vez de perderse', async () => {
    // Un equipo que venía de la versión sin dueño: claves sueltas.
    almacen.set('carts', new Map([['outbox:viejo', { id: 'viejo', number: '001-1' }]]))
    almacen.set('meta', new Map([['offline', { settings: {}, tax_codes: [], reservations: {} }]]))

    activar(MOSTRADOR)
    const adoptados = await useOffline().adoptLegacy(MOSTRADOR)

    expect(adoptados).toBe(1)
    expect(almacen.get('carts')!.has(`t:${MOSTRADOR}:outbox:viejo`)).toBe(true)
    expect(almacen.get('carts')!.has('outbox:viejo')).toBe(false)
    expect(almacen.get('meta')!.has(`t:${MOSTRADOR}:offline`)).toBe(true)
  })
})
