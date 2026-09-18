import { describe, expect, it } from 'vitest'
import type { SyncSale } from './useSync'

/**
 * La regla que gobierna la lista de cuentas abiertas.
 *
 * Se prueba la reconciliación y no el temporizador: lo que importa es que una
 * cuenta que otra caja retomó **desaparezca** de esta pantalla. Mandar solo las
 * suspendidas dejaría cuentas fantasma, y un mesero cobrando una mesa que otro
 * ya cobró es el error que un restaurante no perdona.
 */
function reconcile(current: Map<string, SyncSale>, incoming: SyncSale[]): Map<string, SyncSale> {
  const next = new Map(current)

  for (const sale of incoming) {
    if (sale.status === 'suspended') {
      next.set(sale.id, sale)
    } else {
      next.delete(sale.id)
    }
  }

  return next
}

const sale = (id: string, status: SyncSale['status'], label: string): SyncSale => ({
  id,
  status,
  label,
  sale_type: 'counter',
  total: '100.00',
  terminal_id: 't1',
  employee_id: 'e1'
})

describe('reconciliación de cuentas abiertas', () => {
  it('agrega la cuenta que otra caja suspendió', () => {
    const result = reconcile(new Map(), [sale('s1', 'suspended', 'Mesa 4')])

    expect(result.get('s1')?.label).toBe('Mesa 4')
  })

  it('quita la cuenta que otra caja retomó', () => {
    const current = new Map([['s1', sale('s1', 'suspended', 'Mesa 4')]])

    const result = reconcile(current, [sale('s1', 'draft', 'Mesa 4')])

    expect(result.has('s1')).toBe(false)
  })

  it('quita la cuenta que otra caja cobró', () => {
    const current = new Map([['s1', sale('s1', 'suspended', 'Mesa 4')]])

    expect(reconcile(current, [sale('s1', 'completed', 'Mesa 4')]).size).toBe(0)
  })

  it('no toca las cuentas que no vinieron en el cambio', () => {
    const current = new Map([
      ['s1', sale('s1', 'suspended', 'Mesa 4')],
      ['s2', sale('s2', 'suspended', 'Mesa 7')]
    ])

    const result = reconcile(current, [sale('s1', 'completed', 'Mesa 4')])

    expect(result.size).toBe(1)
    expect(result.get('s2')?.label).toBe('Mesa 7')
  })
})
