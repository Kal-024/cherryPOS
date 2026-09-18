import { afterEach, describe, expect, it, vi } from 'vitest'
import { type KitchenTicket, useKitchen } from './useKitchen'

/**
 * La comanda vista desde el terminal (F1-B).
 *
 * Lo que se fija acá es la lectura que hace el pase: **cuánto lleva esperando**
 * —es lo primero que mira una cocina— y que avanzar una comanda mande el estado
 * siguiente y relea la pantalla, porque el KDS cuelga de una pared y nadie va a
 * refrescarlo a mano.
 */

const ticket = (overrides: Partial<KitchenTicket> = {}): KitchenTicket => ({
  id: 'k1',
  number: 14,
  destination: 'kitchen',
  course: 1,
  status: 'queued',
  sale_id: 's1',
  label: 'Mesa 4',
  notes: null,
  sent_at: new Date(Date.now() - 12 * 60_000).toISOString(),
  fired_at: new Date(Date.now() - 12 * 60_000).toISOString(),
  started_at: null,
  ready_at: null,
  elapsed_seconds: 12 * 60,
  is_late: false,
  lines: [{ id: 'l1', name: 'Lomo', qty: '1.0000', modifiers: ['Término medio'], notes: 'Sin sal' }],
  ...overrides
})

function stub(data: unknown) {
  const sent: { url: string, method?: string, body: Record<string, unknown> }[] = []

  vi.stubGlobal('fetch', (input: string, init: RequestInit = {}) => {
    sent.push({
      url: String(input),
      method: init.method,
      body: init.body ? JSON.parse(String(init.body)) : {}
    })

    return Promise.resolve({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ message: '', data, status: 200 })
    } as Response)
  })

  return sent
}

afterEach(() => {
  useKitchen().stop()
  vi.unstubAllGlobals()
})

describe('comandas en el terminal', () => {
  it('la espera la cuenta el servidor, no el reloj de la pantalla', () => {
    // El KDS cuelga de una pared y su reloj no es el de nadie: si el navegador
    // tiene la hora corrida, restar fechas acá pintaría media cocina de rojo.
    expect(useKitchen().waiting(ticket())).toBe(12)
  })

  it('una comanda recién marchada no lleva esperando nada', () => {
    expect(useKitchen().waiting(ticket({ elapsed_seconds: 0 }))).toBe(0)
  })

  it('marchar suelta el curso que sigue sin decir cuál', async () => {
    const sent = stub([ticket({ course: 2 })])

    // El botón del salón dice "marchar lo que sigue": cuál es eso lo sabe el
    // servidor, que es quien tiene los cursos retenidos.
    await useKitchen().fire('s1')

    expect(sent[0]!.url).toContain('/sales/s1/kitchen/fire')
    expect(sent[0]!.body).toEqual({ course: null })
  })

  it('avanzar manda el estado y relee la pantalla', async () => {
    const sent = stub([ticket({ status: 'preparing' })])
    const kitchen = useKitchen()

    await kitchen.advance('k1', 'preparing', 'kitchen')

    expect(sent[0]!.url).toContain('/kitchen/tickets/k1/status')
    expect(sent[0]!.body.status).toBe('preparing')
    // El KDS cuelga de una pared: nadie va a refrescarlo a mano.
    expect(sent[1]!.url).toContain('/kitchen/tickets?destination=kitchen')
    expect(kitchen.tickets.value[0]!.status).toBe('preparing')
  })

  it('mandar a cocina no elige destino: lo decide el servidor', async () => {
    const sent = stub([ticket()])

    await useKitchen().send('s1')

    expect(sent[0]!.url).toContain('/sales/s1/kitchen/send')
    expect(sent[0]!.body).toEqual({ notes: null })
  })
})
