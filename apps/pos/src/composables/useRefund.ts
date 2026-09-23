import { computed, ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'
import type { Sale } from './useCart'

/**
 * Devoluciones contra el ticket original (H2.6, P-04).
 *
 * Una devolución **no es una entidad aparte**: es una venta con `sale_type`
 * `refund`, cantidades negativas y pago negativo, apuntando al documento que
 * reversa. Por eso esto no monta un carrito propio — reusa el de siempre y solo
 * arma el camino: encontrar el ticket, elegir qué vuelve y cuánto.
 *
 * **El ticket original no es opcional.** Lo dice la decisión y lo exige el ERP,
 * que rechaza la nota de crédito con `refund_original_missing`; pero esa
 * respuesta viaja por la cola asíncrona y llegaría cuando el dinero ya salió del
 * cajón. Por eso el servidor lo comprueba antes, y la pantalla ni siquiera deja
 * empezar sin él.
 */

export interface RefundableLine {
  id: string
  product_id: string | null
  description: string
  qty: string
  /** Lo ya devuelto en devoluciones anteriores de este mismo ticket. */
  returned: string
  /** Lo que todavía se puede devolver. Cero: esa línea ya volvió entera. */
  refundable: string
  unit_price: string
  total: string
}

export interface RefundableSale {
  sale: {
    id: string
    number: string | null
    sale_type: string
    status: string
    total: string
    closed_at: string | null
  }
  lines: RefundableLine[]
  fully_returned: boolean
}

/** Una venta tal como la devuelve el listado, para elegirla por su número. */
export interface SaleRow {
  id: string
  number: string | null
  total: string
  sale_type: string
  status: string
  closed_at: string | null
}

export function useRefund() {
  const searching = ref(false)
  const loading = ref(false)
  const saving = ref(false)

  const matches = shallowRef<SaleRow[]>([])
  const original = shallowRef<RefundableSale | null>(null)

  /** Cuánto se devuelve de cada línea, tecleado por el cajero. */
  const chosen = ref<Record<string, string>>({})

  const total = computed(() =>
    (original.value?.lines ?? []).reduce((sum, line) => {
      const qty = Number(chosen.value[line.id] ?? '0')

      return sum + qty * Number(line.unit_price)
    }, 0)
  )

  const hasSomething = computed(() =>
    Object.values(chosen.value).some((qty) => Number(qty) > 0)
  )

  /** Busca el ticket por su número: es lo que el cliente trae en la mano. */
  async function search(number: string) {
    searching.value = true

    try {
      matches.value = await apiGetList<SaleRow>(
        `/sales?number=${encodeURIComponent(number)}&status=completed&limit=10`
      )
    } finally {
      searching.value = false
    }
  }

  /** Trae lo que queda por devolver, ya descontado lo devuelto antes. */
  async function load(saleId: string) {
    loading.value = true

    try {
      original.value = await apiFetch<RefundableSale>(`/sales/${saleId}/refundable`)
      chosen.value = {}
    } finally {
      loading.value = false
    }
  }

  function forget() {
    original.value = null
    matches.value = []
    chosen.value = {}
  }

  /** Devolver el ticket entero es el caso más común: un botón lo arma. */
  function takeAll() {
    for (const line of original.value?.lines ?? []) {
      if (Number(line.refundable) > 0) chosen.value[line.id] = line.refundable
    }
  }

  /**
   * Abre la devolución y le agrega las líneas elegidas, en negativo.
   *
   * Devuelve la venta lista para cobrar: de ahí en adelante es el panel de pago
   * de siempre, con importes negativos. No hay una segunda forma de cerrar una
   * venta, y por eso una devolución cuadra igual que un ticket.
   */
  async function start(supervisor?: { code: string, pin: string }): Promise<Sale> {
    if (!original.value) throw new Error('refund.no_original')

    saving.value = true

    try {
      const sale = await apiFetch<Sale>('/sales', {
        method: 'POST',
        body: JSON.stringify({
          sale_type: 'refund',
          reverses_sale_id: original.value.sale.id,
          supervisor_code: supervisor?.code,
          supervisor_pin: supervisor?.pin
        })
      })

      for (const line of original.value.lines) {
        const qty = chosen.value[line.id]

        if (!qty || Number(qty) <= 0) continue

        await apiFetch(`/sales/${sale.id}/lines`, {
          method: 'POST',
          body: JSON.stringify({
            kind: 'product',
            product_id: line.product_id,
            qty: `-${qty}`
          })
        })
      }

      return sale
    } finally {
      saving.value = false
    }
  }

  return {
    searching,
    loading,
    saving,
    matches,
    original,
    chosen,
    total,
    hasSomething,
    search,
    load,
    forget,
    takeAll,
    start
  }
}
