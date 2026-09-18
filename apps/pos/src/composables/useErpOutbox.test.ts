import { afterEach, describe, expect, it, vi } from 'vitest'
import { useErpOutbox } from './useErpOutbox'

/**
 * La bandeja de envíos al ERP (P4, §5 del contrato).
 *
 * Dos cosas se fijan acá. Que una diferencia de impuesto **se detecte aunque sea
 * de un centavo** —es el detector de que los dos motores de cálculo se están
 * separando, y en el peor caso se descubre con cientos de documentos emitidos— y
 * que cerrar una excepción a mano exija motivo, porque quien revise esto dentro
 * de seis meses necesita saber por qué ese ticket nunca llegó.
 */

const entry = (overrides: Record<string, unknown> = {}) => ({
  id: 'o1',
  sale_id: 's1',
  sale_number: '001-CTR-2026-000001',
  sale_total: '115.00',
  closed_at: '2026-09-17T10:00:00Z',
  status: 'sent',
  attempts: 1,
  next_attempt_at: null,
  http_status: 201,
  error_code: null,
  error_message: null,
  erp_document_number: 'CSI-000045',
  duplicate: false,
  tax_difference: '0.00',
  sent_at: '2026-09-17T10:00:05Z',
  resolved_at: null,
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
  vi.unstubAllGlobals()
})

describe('estado de la integración', () => {
  it('sin ERP configurado el POS opera solo, y eso no es un error', async () => {
    stub({
      configured: false,
      base_url: null,
      company_id: null,
      settings_synced_at: null,
      queue: { pending: 0, exceptions: 0, sent_today: 0 },
      effective: {
        offline_max_hours: 72,
        offline_pin_mode: 'session_grant',
        require_shift: true,
        layout_profile: 'scan_first'
      }
    })

    const outbox = useErpOutbox()
    await outbox.loadStatus()

    expect(outbox.status.value?.configured).toBe(false)
    // Lo que rige hoy es lo que hay que mirar cuando la caja se comporta
    // distinto de lo que alguien esperaba.
    expect(outbox.status.value?.effective.offline_max_hours).toBe(72)
  })

  it('bajar la configuración es una lectura, sin cuerpo', async () => {
    const sent = stub({ applied: { 'pos.require_shift': false } })

    const applied = await useErpOutbox().pullSettings()

    expect(sent[0]!.method).toBe('POST')
    expect(sent[0]!.body).toEqual({})
    expect(applied).toEqual({ 'pos.require_shift': false })
  })
})

describe('bandeja de envíos al ERP', () => {
  it('un centavo de diferencia ya es divergencia', async () => {
    stub([entry(), entry({ id: 'o2', tax_difference: '0.01' })])

    const outbox = useErpOutbox()
    await outbox.list()

    expect(outbox.divergences.value).toHaveLength(1)
    expect(outbox.divergences.value[0]!.id).toBe('o2')
  })

  it('un envío correcto no aparece como divergencia', async () => {
    stub([entry(), entry({ id: 'o3', tax_difference: null })])

    const outbox = useErpOutbox()
    await outbox.list()

    expect(outbox.divergences.value).toEqual([])
  })

  it('las excepciones se cuentan aparte de lo que sigue en cola', async () => {
    stub([
      entry({ id: 'o4', status: 'exception', error_code: 'instrument_unsupported' }),
      entry({ id: 'o5', status: 'pending' })
    ])

    const outbox = useErpOutbox()
    await outbox.list()

    expect(outbox.exceptions.value).toHaveLength(1)
    expect(outbox.exceptions.value[0]!.error_code).toBe('instrument_unsupported')
  })

  it('cerrar a mano manda el motivo', async () => {
    const sent = stub(entry({ resolved_at: '2026-09-17T12:00:00Z' }))

    await useErpOutbox().resolve('o1', '  Tarjeta de regalo: A3 sigue abierto  ')

    expect(sent[0]!.url).toContain('/erp/outbox/o1/resolve')
    expect(sent[0]!.body.reason).toBe('Tarjeta de regalo: A3 sigue abierto')
  })
})

/**
 * La bandeja de conciliación en el terminal (§12.8, Q-04).
 *
 * Lo que se fija: que cerrar una fila **no fusione nada** —solo deje constancia
 * de qué se hizo— porque fusionar por nombre es justo lo que la decisión
 * prohíbe, y que leer maestros no mande parámetros que el POS no debería
 * elegir: el incremento lo decide el servidor con su propia marca.
 */
describe('conciliación de maestros', () => {
  it('cerrar una fila manda el motivo y nada más', async () => {
    const sent = stub(null)

    await useErpOutbox().resolveReconciliation('r1', '  Cargado en el ERP  ')

    expect(sent[0]!.url).toContain('/erp/reconciliation/r1/resolve')
    expect(sent[0]!.body).toEqual({ resolution: 'Cargado en el ERP' })
  })

  it('leer maestros no elige qué bajar', async () => {
    const sent = stub({ products: { created: 2, updated: 1 }, customers: { created: 0, updated: 0 } })

    const result = await useErpOutbox().pullMasters()

    // El POS no manda `updated_since`: la marca es del ERP, y usar la propia
    // abriría una ventana de cambios invisibles (§12.3).
    expect(sent[0]!.body).toEqual({})
    expect(result.products.created).toBe(2)
  })
})
