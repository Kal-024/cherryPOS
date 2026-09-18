import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'
import type { Customer } from './useCustomers'

/**
 * Crédito del POS: la versión reducida (P-02, Q-10).
 *
 * Límite, saldo, consumo, abono en caja, hasta tres autorizados, corte
 * configurable por cliente y estado de cuenta a pedido. **Intereses y planes de
 * pago son del ERP** y no se implementan acá ni se inventan.
 *
 * Dos reglas que la pantalla tiene que respetar sin excusas:
 *
 *  - **El límite bloquea la venta**, no avisa. Lo aplica el servidor al
 *    facturar; acá solo se muestra cuánto queda.
 *  - **El bloqueo de cuenta es manual del supervisor**, con motivo obligatorio:
 *    es una decisión de negocio, no un automatismo por días de mora.
 */

export interface CreditAccountRow {
  id: string
  customer_id: string
  customer_name: string
  national_id: string | null
  credit_limit: string
  balance: string
  available: string
  is_blocked: boolean
  blocked_reason: string | null
  cut_off_day: number
}

export interface CreditAccount {
  id: string
  customer_id: string
  credit_limit: string
  balance: string
  cut_off_day: number
  is_blocked: boolean
  blocked_reason: string | null
}

export interface AuthorizedPerson {
  id: string
  relationship: string | null
  is_active: boolean
  person?: { full_name: string, national_id: string | null, phone: string | null } | null
}

export interface CreditDetail {
  account: CreditAccount
  customer: Customer
  authorized: AuthorizedPerson[]
  available: string
}

export interface StatementEntry {
  date: string | null
  kind: 'charge' | 'payment' | 'adjustment'
  amount: string
  sale_id: string | null
  comment: string | null
}

export interface Statement {
  customer: { name: string, national_id: string | null }
  period: { from: string, to: string, cut_off_day: number }
  opening_balance: string
  charges: string
  payments: string
  closing_balance: string
  credit_limit: string
  available: string
  is_blocked: boolean
  entries: StatementEntry[]
}

/** Tres, y no es un número redondo elegido al azar: lo fija G-10. */
export const MAX_AUTHORIZED = 3

export function useCredit() {
  const accounts = shallowRef<CreditAccountRow[]>([])
  const loading = ref(false)
  const saving = ref(false)

  async function list(options: { withBalanceOnly?: boolean, blockedOnly?: boolean } = {}) {
    loading.value = true

    try {
      const query = new URLSearchParams()
      if (options.withBalanceOnly) query.set('with_balance_only', '1')
      if (options.blockedOnly) query.set('blocked_only', '1')

      accounts.value = await apiGetList<CreditAccountRow>(`/credit/accounts?${query.toString()}`)
    } finally {
      loading.value = false
    }
  }

  async function detail(customerId: string): Promise<CreditDetail | null> {
    try {
      return await apiFetch<CreditDetail>(`/customers/${customerId}/credit`)
    } catch (err) {
      // 404 acá significa "este cliente no tiene cuenta todavía", que es el
      // estado normal de cualquier cliente de efectivo.
      if (err instanceof Error && 'status' in err && err.status === 404) return null
      throw err
    }
  }

  async function open(customerId: string, creditLimit: string, cutOffDay: number) {
    saving.value = true

    try {
      return await apiFetch<CreditAccount>(`/customers/${customerId}/credit`, {
        method: 'POST',
        body: JSON.stringify({ credit_limit: creditLimit.trim(), cut_off_day: cutOffDay })
      })
    } finally {
      saving.value = false
    }
  }

  /** Abono en caja: el dinero entra al cajón del turno abierto (H8.4). */
  async function pay(customerId: string, amount: string, method = 'cash', comment?: string) {
    saving.value = true

    try {
      return await apiFetch<{ account: CreditAccount }>(`/customers/${customerId}/credit/payments`, {
        method: 'POST',
        body: JSON.stringify({ amount: amount.trim(), method, comment: comment?.trim() || null })
      })
    } finally {
      saving.value = false
    }
  }

  async function block(customerId: string, reason: string) {
    saving.value = true

    try {
      return await apiFetch<CreditAccount>(`/customers/${customerId}/credit/block`, {
        method: 'POST',
        body: JSON.stringify({ reason: reason.trim() })
      })
    } finally {
      saving.value = false
    }
  }

  async function unblock(customerId: string) {
    saving.value = true

    try {
      return await apiFetch<CreditAccount>(`/customers/${customerId}/credit/unblock`, { method: 'POST' })
    } finally {
      saving.value = false
    }
  }

  async function addAuthorized(
    customerId: string,
    input: { name: string, national_id: string, phone?: string, relationship?: string }
  ) {
    saving.value = true

    try {
      return await apiFetch<AuthorizedPerson>(`/customers/${customerId}/credit/authorized`, {
        method: 'POST',
        body: JSON.stringify({
          name: input.name.trim(),
          // Sin cédula no hay autorizado: es lo que permite reconocerlo en caja
          // y lo que une a la persona con el resto del sistema (B-11).
          national_id: input.national_id.trim(),
          phone: input.phone?.trim() || null,
          relationship: input.relationship?.trim() || null
        })
      })
    } finally {
      saving.value = false
    }
  }

  async function removeAuthorized(customerId: string, authorizedId: string) {
    await apiFetch(`/customers/${customerId}/credit/authorized/${authorizedId}`, { method: 'DELETE' })
  }

  async function statement(customerId: string, date?: string): Promise<Statement> {
    const query = date ? `?date=${encodeURIComponent(date)}` : ''

    return apiFetch<Statement>(`/customers/${customerId}/credit/statement${query}`)
  }

  return {
    accounts,
    loading,
    saving,
    list,
    detail,
    open,
    pay,
    block,
    unblock,
    addAuthorized,
    removeAuthorized,
    statement
  }
}
