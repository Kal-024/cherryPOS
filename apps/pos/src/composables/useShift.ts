import { computed, ref, shallowRef } from 'vue'
import { apiFetch, isApiError } from './httpClient'

/**
 * Turno de caja en el terminal (G-04, D-06, H4).
 *
 * **El turno es del equipo, no de la persona**: el cajón de dinero es físico y
 * el arqueo cuenta ese cajón. Por eso la caja pregunta una vez por el turno de
 * *la terminal* y no por el de cada cajero que entra.
 *
 * Nada se vende sin turno abierto, así que esta es la primera pregunta que hace
 * la pantalla de venta al arrancar.
 */

export interface CurrencyCut {
  currency_code: string
  expected: string
  counted: string
  difference: string
}

export interface CashierCut {
  employee_code: string
  employee_name: string
  sales: number
  total: string
}

export interface ShiftSummary {
  shift: {
    id: string
    code: string
    status: string
    opening_float: string
    /** Congelada al abrir el turno: releerlo mañana con otra tasa daría otro número (Q-06). */
    exchange_rate: string | null
    opened_at: string
    closed_at: string | null
  }
  by_currency: CurrencyCut[]
  by_cashier: CashierCut[]
  sales: { count: number, total: string, tax_total: string, by_method: Record<string, string> }
  denominations: { currency_code: string, denomination: string, count: number, subtotal: string }[]
}

export interface Denomination {
  id: string
  currency_code: string
  value: string
  kind: 'bill' | 'coin'
}

export interface DenominationCount {
  denomination_value: string
  currency_code: string
  count: number
}

const summary = shallowRef<ShiftSummary | null>(null)
const denominations = shallowRef<Denomination[]>([])
const loading = ref(false)

/**
 * El cierre en curso.
 *
 * El botón vive en la cabecera —está en todas las pantallas— y el formulario de
 * arqueo en la de venta. Sin este estado compartido no habría forma de cerrar el
 * turno: el panel de cierre existía y no tenía cómo abrirse, que es justo lo que
 * encontró la compuerta automatizada.
 */
const closing = ref(false)

export function useShift() {
  const isOpen = computed(() => summary.value?.shift.status === 'open')
  const code = computed(() => summary.value?.shift.code ?? null)

  async function refresh() {
    loading.value = true

    try {
      summary.value = await apiFetch<ShiftSummary>('/shifts/current')
    } catch (err) {
      // 404 es el estado normal antes de abrir la caja, no un fallo.
      if (isApiError(err) && err.status === 404) {
        summary.value = null
      } else {
        throw err
      }
    } finally {
      loading.value = false
    }
  }

  async function loadDenominations() {
    try {
      denominations.value = await apiFetch<Denomination[]>('/cash/denominations')
    } catch (err) {
      if (isApiError(err) && err.status === 404) {
        denominations.value = []
      } else {
        throw err
      }
    }
  }

  async function open(openingFloat: string, counts: DenominationCount[] = []) {
    await apiFetch('/shifts', {
      method: 'POST',
      body: JSON.stringify({ opening_float: openingFloat, counts })
    })

    await refresh()
  }

  /**
   * Cierra con el conteo físico y devuelve el corte.
   *
   * El resultado trae la diferencia **por moneda**: un consolidado escondería
   * que sobran córdobas y faltan dólares (Q-06).
   */
  async function close(counts: DenominationCount[]): Promise<ShiftSummary> {
    // Se manda el conteo completo, ceros incluidos. Un cero contado **es**
    // información: dice que se miró y no había. Filtrarlos además dejaba sin
    // poder cerrar el cajón que quedó vacío —el servidor exige al menos una
    // fila— con un "hay errores de validación" que no explicaba nada.
    const result = await apiFetch<ShiftSummary>('/shifts/close', {
      method: 'POST',
      body: JSON.stringify({ counts })
    })

    summary.value = null
    closing.value = false

    return result
  }

  async function movement(direction: 'in' | 'out', amount: string, reason: string) {
    await apiFetch('/cash/movements', {
      method: 'POST',
      body: JSON.stringify({ direction, amount, reason })
    })

    await refresh()
  }

  return {
    summary,
    denominations,
    loading,
    closing,
    isOpen,
    code,
    refresh,
    loadDenominations,
    open,
    close,
    movement
  }
}
