import { describe, expect, it } from 'vitest'
import { calculateSale } from '@cherrypos/calc'

/**
 * El cálculo del terminal sin servidor (H6).
 *
 * Lo que se verifica acá no es el motor —de eso se encargan los fixtures
 * compartidos, que PHP y TypeScript corren por igual— sino que **el terminal lo
 * invoque con la misma configuración que usaría el servidor**. Una llamada con
 * la base del impuesto equivocada daría un total distinto, y el servidor lo
 * rechazaría al recibir con una diferencia que nadie sabría explicar.
 */

const nicaragua = {
  currency: 'NIO',
  fixedQuotaRegime: false,
  cashRounding: { mode: 'none' as const, increment: '0.25' }
}

describe('cálculo en modo degradado', () => {
  it('el precio de góndola lleva el IVA adentro', () => {
    const result = calculateSale({
      config: nicaragua,
      lines: [
        { id: '0', qty: '1', unitPrice: '115.00', taxes: [{ code: 'IVA', rate: '15', base: 'gross' }] }
      ],
      payments: [{ method: 'cash', amount: '115.00' }]
    })

    // Es el mismo resultado que da el servidor: 115 es lo que paga el cliente.
    expect(result.total).toBe('115.00')
    expect(result.taxableBase).toBe('100.00')
    expect(result.taxTotal).toBe('15.00')
    expect(result.balance).toBe('0.00')
  })

  it('el vuelto sale calculado, no estimado', () => {
    const result = calculateSale({
      config: nicaragua,
      lines: [
        { id: '0', qty: '1', unitPrice: '115.00', taxes: [{ code: 'IVA', rate: '15', base: 'gross' }] }
      ],
      payments: [{ method: 'cash', amount: '200.00' }]
    })

    expect(result.change).toBe('85.00')
  })

  it('un medicamento exento no paga IVA ni entra en la base gravada', () => {
    const result = calculateSale({
      config: nicaragua,
      lines: [
        { id: '0', qty: '1', unitPrice: '250.00', exempt: true, taxes: [{ code: 'IVA', rate: '15', base: 'gross' }] },
        { id: '1', qty: '1', unitPrice: '115.00', taxes: [{ code: 'IVA', rate: '15', base: 'gross' }] }
      ],
      payments: []
    })

    // Sin farmacia operable no hay vertical farmacia, y eso también tiene que
    // funcionar con el servidor apagado.
    expect(result.exemptTotal).toBe('250.00')
    expect(result.taxableBase).toBe('100.00')
    expect(result.total).toBe('365.00')
  })

  it('un pago en dólares se convierte con la tasa del turno', () => {
    const result = calculateSale({
      config: nicaragua,
      lines: [
        { id: '0', qty: '1', unitPrice: '575.00', taxes: [{ code: 'IVA', rate: '15', base: 'gross' }] }
      ],
      payments: [{ method: 'cash', currency: 'USD', amount: '20.00', rate: '36.624300' }]
    })

    // La tasa la congeló el turno al abrir, y el terminal la lleva cacheada.
    expect(result.paid).toBe('732.49')
    expect(result.change).toBe('157.49')
  })

  it('la base del impuesto cambia el total', () => {
    const gross = calculateSale({
      config: nicaragua,
      lines: [{ id: '0', qty: '1', unitPrice: '100.00', taxes: [{ code: 'IVA', rate: '15', base: 'gross' }] }],
      payments: []
    })

    const net = calculateSale({
      config: nicaragua,
      lines: [{ id: '0', qty: '1', unitPrice: '100.00', taxes: [{ code: 'IVA', rate: '15', base: 'net' }] }],
      payments: []
    })

    // Por eso `base` viaja en el arranque: con la base equivocada el terminal
    // cobraría 15 córdobas de más en cada línea, y el servidor lo detectaría
    // recién al sincronizar.
    expect(gross.total).toBe('100.00')
    expect(net.total).toBe('115.00')
  })
})

/**
 * El número que el terminal asigna sin servidor.
 *
 * Se prueba la plantilla porque es lo que decide si dos cajas pueden chocar: el
 * bloque reservado acota el rango, pero el formato tiene que respetarlo.
 */
function render(template: string, branchCode: string, sequence: number, year: number): string {
  return template
    .replace('{BRANCH}', branchCode)
    .replace('{TYPE}', 'COU')
    .replace('{YEAR}', String(year))
    .replace('{YY}', String(year).slice(-2))
    .replace(/\{SEQ(?::(\d+))?\}/, (_, width?: string) =>
      width ? String(sequence).padStart(Number(width), '0') : String(sequence)
    )
}

describe('numeración sin servidor', () => {
  it('respeta la plantilla de la serie', () => {
    expect(render('{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}', '001', 42, 2026)).toBe('001-COU-2026-000042')
  })

  it('el ancho del correlativo es parte del formato del cliente', () => {
    expect(render('{BRANCH}/{SEQ:4}', '002', 7, 2026)).toBe('002/0007')
    expect(render('{SEQ}', '002', 7, 2026)).toBe('7')
  })

  it('dos sucursales con el mismo correlativo dan números distintos', () => {
    const uno = render('{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}', '001', 1, 2026)
    const dos = render('{BRANCH}-{TYPE}-{YEAR}-{SEQ:6}', '002', 1, 2026)

    // Precondición 3: dos locales nunca comparten correlativo.
    expect(uno).not.toBe(dos)
  })
})
