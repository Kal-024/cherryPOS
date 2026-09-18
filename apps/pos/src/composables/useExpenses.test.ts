import { afterEach, describe, expect, it, vi } from 'vitest'
import { emptyExpenseDraft, useExpenses, type Expense } from './useExpenses'

/**
 * Gastos: las dos reglas que el resto de la pantalla da por ciertas (B-14).
 *
 * *"Sin esto el POS reporta ingresos, no utilidad"* — y reportaría mal si un
 * gasto anulado siguiera sumando, o si el IVA viajara metido dentro del monto.
 */

const expense = (overrides: Partial<Expense> = {}): Expense => ({
  id: 'e1',
  category_id: 'c1',
  supplier_id: null,
  document_number: null,
  document_date: '2026-09-16',
  description: 'Luz',
  currency_code: 'NIO',
  amount: '100.00',
  tax_amount: '15.00',
  total: '115.00',
  payment_method: 'cash',
  status: 'recorded',
  void_reason: null,
  cash_movement_id: 'm1',
  ...overrides
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('total del período', () => {
  it('suma los gastos registrados', () => {
    const expenses = useExpenses()
    expenses.expenses.value = [expense(), expense({ id: 'e2', total: '230.00' })]

    expect(expenses.total.value).toBe('345.00')
  })

  it('no suma los anulados', () => {
    const expenses = useExpenses()
    expenses.expenses.value = [
      expense(),
      expense({ id: 'e2', total: '230.00', status: 'voided', void_reason: 'Doble registro' })
    ]

    // El anulado sigue en la lista —se anula, no se borra (P1)— pero no puede
    // seguir contando: el arqueo del turno ya lo compensó.
    expect(expenses.total.value).toBe('115.00')
  })
})

describe('registro de un gasto', () => {
  it('el impuesto en blanco viaja como cero explícito', async () => {
    let body: Record<string, unknown> = {}

    vi.stubGlobal('fetch', (_input: string, init: RequestInit) => {
      body = JSON.parse(String(init.body))

      return Promise.resolve({
        ok: true,
        status: 201,
        json: () => Promise.resolve({ message: '', data: expense(), status: 201 })
      } as Response)
    })

    const expenses = useExpenses()
    await expenses.record({ ...emptyExpenseDraft(), category_id: 'c1', description: 'Luz', amount: '100' })

    // "No lleva IVA" es una afirmación, no un campo que se olvidó: el impuesto
    // del gasto es crédito fiscal y el servidor necesita saber que es cero.
    expect(body.tax_amount).toBe('0')
    expect(body.amount).toBe('100')
    expect(body.document_number).toBeNull()
  })
})
