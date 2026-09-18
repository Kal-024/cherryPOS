import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * El mínimo operativo de reportes de F1 (P-07, D-20).
 *
 * El módulo completo es F2. Acá está lo que un local no puede cerrar el día sin
 * tener: las ventas del día **por cajero y por terminal** y la bitácora. El
 * corte de turno vive en la caja y los créditos en su propia pantalla.
 */

export interface SalesByEmployee {
  employee_id: string
  code: string
  name: string
  sales_count: number
  total: string
  discount_total: string
}

export interface SalesByTerminal {
  terminal_id: string
  code: string
  name: string
  sales_count: number
  total: string
}

export interface DailySales {
  from: string
  to: string
  totals: {
    sales_count: number
    total: string
    discount_total: string
    tax_total: string
    /** Exento y base gravada van separados: el libro los declara distinto. */
    exempt_total: string
    taxable_base: string
  }
  by_employee: SalesByEmployee[]
  by_terminal: SalesByTerminal[]
}

export interface AuditEntry {
  id: string
  event: string
  entity_type: string | null
  entity_id: string | null
  changes: Record<string, unknown> | null
  context: Record<string, unknown> | null
  occurred_at: string
  employee_name: string | null
  terminal_code: string | null
}

export function useReports() {
  const sales = shallowRef<DailySales | null>(null)
  const entries = shallowRef<AuditEntry[]>([])
  const loading = ref(false)

  async function dailySales(from: string, to: string) {
    loading.value = true

    try {
      sales.value = await apiFetch<DailySales>(`/reports/daily-sales?from=${from}&to=${to}`)
    } finally {
      loading.value = false
    }
  }

  /**
   * La bitácora se lee y nada más.
   *
   * No hay forma de corregirla desde ninguna pantalla, y es deliberado: una
   * bitácora que el propio sistema puede reescribir no prueba nada (G-11).
   */
  async function auditLog(filters: { from?: string, to?: string, event?: string } = {}) {
    loading.value = true

    try {
      const query = new URLSearchParams()
      if (filters.from) query.set('from', filters.from)
      if (filters.to) query.set('to', filters.to)
      if (filters.event) query.set('event', filters.event)

      entries.value = await apiGetList<AuditEntry>(`/reports/audit-log?${query.toString()}`)
    } finally {
      loading.value = false
    }
  }

  return { sales, entries, loading, dailySales, auditLog }
}
