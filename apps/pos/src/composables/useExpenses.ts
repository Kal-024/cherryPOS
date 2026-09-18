import { computed, ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * Gastos categorizados (B-14, H4).
 *
 * *"Sin esto el POS reporta ingresos, no utilidad."* Viven junto al turno
 * porque **un gasto pagado del cajón es un movimiento de caja**: registrarlo
 * aparte obligaría a cuadrar dos veces la misma plata y el arqueo no podría
 * explicar el faltante.
 *
 * Dos separaciones que parecen contables y son operativas:
 *
 *  - **El impuesto va aparte del monto**: el IVA de un gasto es crédito fiscal,
 *    no costo.
 *  - **Un gasto no se edita ni se borra**: se anula con motivo (A-05, P1).
 */

export interface ExpenseCategory {
  id: string
  code: string
  name: string
  /** `fixed` o `variable`: la distinción que pide el punto de equilibrio. */
  behaviour: 'fixed' | 'variable'
}

export interface Expense {
  id: string
  category_id: string
  supplier_id: string | null
  document_number: string | null
  document_date: string | null
  description: string
  currency_code: string
  amount: string
  tax_amount: string
  total: string
  payment_method: 'cash' | 'card' | 'transfer' | 'credit' | 'other'
  status: 'recorded' | 'voided'
  void_reason: string | null
  cash_movement_id: string | null
  category?: { code: string, name: string, behaviour: string } | null
  supplier?: { person?: { full_name: string } | null } | null
}

export interface ExpenseDraft {
  category_id: string | null
  description: string
  amount: string
  tax_amount: string
  document_number: string
  document_date: string
  payment_method: Expense['payment_method']
}

export function emptyExpenseDraft(): ExpenseDraft {
  return {
    category_id: null,
    description: '',
    amount: '',
    tax_amount: '',
    document_number: '',
    document_date: new Date().toISOString().slice(0, 10),
    // El caso común es pagar del cajón, y es además el que obliga a tener turno
    // abierto: el dinero sale de una caja concreta.
    payment_method: 'cash'
  }
}

const categories = shallowRef<ExpenseCategory[]>([])
const categoriesLoaded = ref(false)

export function useExpenses() {
  const expenses = shallowRef<Expense[]>([])
  const loading = ref(false)
  const saving = ref(false)

  /** Suma de lo no anulado: lo anulado sigue en la lista pero no cuenta. */
  const total = computed(() =>
    expenses.value
      .filter((expense) => expense.status !== 'voided')
      .reduce((sum, expense) => sum + Number.parseFloat(expense.total), 0)
      .toFixed(2)
  )

  async function loadCategories(force = false) {
    if (categoriesLoaded.value && !force) return

    categories.value = await apiGetList<ExpenseCategory>('/expenses/categories')
    categoriesLoaded.value = true
  }

  async function list(options: { from?: string, to?: string, categoryId?: string | null } = {}) {
    loading.value = true

    try {
      const query = new URLSearchParams()
      if (options.from) query.set('from', options.from)
      if (options.to) query.set('to', options.to)
      if (options.categoryId) query.set('category_id', options.categoryId)

      expenses.value = await apiGetList<Expense>(`/expenses?${query.toString()}`)
    } finally {
      loading.value = false
    }
  }

  async function record(draft: ExpenseDraft): Promise<Expense> {
    saving.value = true

    try {
      return await apiFetch<Expense>('/expenses', {
        method: 'POST',
        body: JSON.stringify({
          category_id: draft.category_id,
          description: draft.description.trim(),
          amount: draft.amount.trim(),
          // Cero explícito, no ausente: "no lleva IVA" es una afirmación del
          // supervisor, y el servidor la guarda como tal.
          tax_amount: draft.tax_amount.trim() === '' ? '0' : draft.tax_amount.trim(),
          document_number: draft.document_number.trim() || null,
          document_date: draft.document_date || null,
          payment_method: draft.payment_method
        })
      })
    } finally {
      saving.value = false
    }
  }

  /** Anular, nunca borrar: el movimiento de caja que lo pagó ya existe. */
  async function voidExpense(id: string, reason: string): Promise<Expense> {
    saving.value = true

    try {
      return await apiFetch<Expense>(`/expenses/${id}/void`, {
        method: 'POST',
        body: JSON.stringify({ reason: reason.trim() })
      })
    } finally {
      saving.value = false
    }
  }

  return { expenses, categories, loading, saving, total, loadCategories, list, record, voidExpense }
}
