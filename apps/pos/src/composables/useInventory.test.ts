import { afterEach, describe, expect, it, vi } from 'vitest'
import { useInventory } from './useInventory'

/**
 * Lo que el terminal manda al registrar un movimiento de inventario (B-03).
 *
 * El stock es la suma de los movimientos, así que lo único que puede viajar es
 * un movimiento: `qty` es la **diferencia con signo** en un ajuste, no el nuevo
 * saldo, y una entrada lleva su costo porque alimenta la valuación.
 */

function captureBody() {
  const sent: { url: string, body: Record<string, unknown> }[] = []

  vi.stubGlobal('fetch', (input: string, init: RequestInit = {}) => {
    // Las consultas viajan sin cuerpo: lo que se mira en esas es la URL.
    sent.push({ url: String(input), body: init.body ? JSON.parse(String(init.body)) : {} })

    return Promise.resolve({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ message: '', data: {}, status: 201 })
    } as Response)
  })

  return sent
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('movimientos de inventario', () => {
  it('el ajuste manda la diferencia con signo y su motivo', async () => {
    const sent = captureBody()

    await useInventory().adjust({
      productId: 'p1',
      locationId: 'l1',
      qty: '-3',
      comment: ' Merma por rotura '
    })

    expect(sent[0]!.url).toContain('/inventory/adjustments')
    expect(sent[0]!.body.qty).toBe('-3')
    // Un ajuste sin motivo es lo que después nadie sabe explicar; el servidor lo
    // exige y el terminal no lo manda vacío por accidente.
    expect(sent[0]!.body.comment).toBe('Merma por rotura')
  })

  it('la entrada lleva costo unitario', async () => {
    const sent = captureBody()

    await useInventory().receive({
      productId: 'p1',
      locationId: 'l1',
      qty: '12',
      unitCost: '38.50'
    })

    expect(sent[0]!.url).toContain('/inventory/receipts')
    expect(sent[0]!.body.unit_cost).toBe('38.50')
    expect(sent[0]!.body.comment).toBeNull()
  })

  it('las existencias se piden siempre para una ubicación concreta', async () => {
    const sent = captureBody()
    const inventory = useInventory()

    await inventory.balances('l1', { belowMin: true, search: 'queso' })

    // Multi-almacén desde el día uno (B-07): un saldo sin ubicación no
    // significa nada.
    expect(sent[0]!.url).toContain('location_id=l1')
    expect(sent[0]!.url).toContain('below_min=1')
    expect(sent[0]!.url).toContain('search=queso')
  })
})
