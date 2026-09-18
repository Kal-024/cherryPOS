import { afterEach, describe, expect, it, vi } from 'vitest'
import { useSettings } from './useSettings'

/**
 * La configuración del POS vista desde el terminal (D-12, B-09).
 *
 * Lo que se fija acá es la forma del envío: las claves llevan punto y viajan
 * **dentro** de un objeto `settings`. Mandarlas como campos sueltos haría que el
 * validador de Laravel leyera `cash.rounding_mode` como "la clave
 * `rounding_mode` dentro del objeto `cash`" y la regla no se aplicaría nunca:
 * el redondeo entraría sin validar, que es justo lo que no puede pasar con algo
 * que los dos motores de cálculo leen.
 */

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

const payload = {
  values: {
    'receipt.footer': 'Gracias por su compra',
    'receipt.notice': '',
    'cash.rounding_mode': 'none',
    'cash.rounding_increment': '0.25',
    'tax.fixed_quota_regime': false,
    'pos.offline_max_hours': 72
  },
  fixed: {
    base_currency: 'NIO',
    secondary_currency: 'USD',
    business_profile: 'retail',
    costing_method: 'AVG',
    require_shift: true,
    refund_requires_original: true,
    temporary_item_daily_limit: 5
  }
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('configuración del POS', () => {
  it('separa lo editable de lo que viene de la instalación', async () => {
    stub(payload)

    const settings = useSettings()
    await settings.load()

    expect(settings.values.value?.['cash.rounding_mode']).toBe('none')
    // El par de monedas y el método de costeo se deciden al instalar: la
    // pantalla los muestra para poder explicar por qué no se tocan.
    expect(settings.fixed.value?.secondary_currency).toBe('USD')
    expect(settings.fixed.value?.costing_method).toBe('AVG')
  })

  it('las claves con punto viajan dentro de settings', async () => {
    const sent = stub(payload)

    await useSettings().save({ 'cash.rounding_mode': 'up', 'cash.rounding_increment': '0.25' })

    expect(sent[0]!.method).toBe('PUT')
    expect(sent[0]!.body).toEqual({
      settings: { 'cash.rounding_mode': 'up', 'cash.rounding_increment': '0.25' }
    })
  })

  it('sin tasa cargada la pantalla no revienta', async () => {
    vi.stubGlobal('fetch', () =>
      Promise.resolve({
        ok: false,
        status: 404,
        json: () => Promise.resolve({ message: 'sin tasa', status: 404 })
      } as Response))

    const settings = useSettings()
    await settings.loadRate('USD')

    // Es el estado de una instalación nueva, no un fallo: el supervisor todavía
    // no cargó la tasa del dólar.
    expect(settings.rate.value).toBeNull()
  })

  it('dar de baja una denominación no la borra', async () => {
    const sent = stub(null)

    await useSettings().removeDenomination('d1')

    expect(sent[0]!.method).toBe('DELETE')
    expect(sent[0]!.url).toContain('/cash/denominations/d1')
  })
})
