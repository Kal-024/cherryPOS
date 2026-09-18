import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * Existencias y su libro mayor (B-03, H1.3).
 *
 * **No hay forma de "poner el stock en 40".** Hay una de registrar un ajuste de
 * +8 con su motivo, y el saldo es la suma. Es la inmutabilidad de los documentos
 * aplicada al inventario: un número editable no se puede auditar.
 *
 * El saldo que se lista sale de la caché `inv_stock_balances`; la fuente de
 * verdad es el libro. Que sea derivable es justo lo que lo hace seguro.
 */

export interface Location {
  id: string
  code: string
  name: string
  is_sales_default: boolean
}

export interface StockRow {
  product_id: string
  sku: string
  name: string
  qty: string
  min_stock: string | null
  uom_code: string | null
}

export interface Movement {
  id: string
  qty: string
  unit_cost: string | null
  reason: string
  comment: string | null
  occurred_at: string
  product?: { sku: string, name: string } | null
  location?: { code: string, name: string } | null
  lot?: { code: string, expires_on: string | null } | null
}

export interface ExpiredLot {
  id: string
  code: string
  expires_on: string | null
  qty: string
  product?: { sku: string, name: string } | null
}

const locations = shallowRef<Location[]>([])
const locationsLoaded = ref(false)

export function useInventory() {
  const rows = shallowRef<StockRow[]>([])
  const movements = shallowRef<Movement[]>([])
  const expired = shallowRef<ExpiredLot[]>([])
  const loading = ref(false)
  const saving = ref(false)

  async function loadLocations(force = false) {
    if (locationsLoaded.value && !force) return

    locations.value = await apiGetList<Location>('/inventory/locations')
    locationsLoaded.value = true
  }

  async function balances(locationId: string, options: { search?: string, belowMin?: boolean } = {}) {
    loading.value = true

    try {
      const query = new URLSearchParams({ location_id: locationId })
      if (options.search) query.set('search', options.search)
      if (options.belowMin) query.set('below_min', '1')

      rows.value = await apiGetList<StockRow>(`/inventory/balances?${query.toString()}`)
    } finally {
      loading.value = false
    }
  }

  /** El libro: responde "¿qué pasó?", que es otra pregunta que "¿cuánto hay?". */
  async function loadMovements(options: { productId?: string, locationId?: string } = {}) {
    const query = new URLSearchParams()
    if (options.productId) query.set('product_id', options.productId)
    if (options.locationId) query.set('location_id', options.locationId)

    movements.value = await apiGetList<Movement>(`/inventory/movements?${query.toString()}`)
  }

  /** Alerta, no reporte: es lo único del inventario que produce acción directa. */
  async function loadExpiredLots(locationId: string) {
    expired.value = await apiGetList<ExpiredLot>(`/inventory/expired-lots?location_id=${locationId}`)
  }

  /** `qty` es la **diferencia con signo**, nunca el nuevo saldo. */
  async function adjust(input: { productId: string, locationId: string, qty: string, comment: string }) {
    saving.value = true

    try {
      return await apiFetch('/inventory/adjustments', {
        method: 'POST',
        body: JSON.stringify({
          product_id: input.productId,
          location_id: input.locationId,
          qty: input.qty.trim(),
          comment: input.comment.trim()
        })
      })
    } finally {
      saving.value = false
    }
  }

  async function receive(input: {
    productId: string
    locationId: string
    qty: string
    unitCost: string
    comment?: string
  }) {
    saving.value = true

    try {
      return await apiFetch('/inventory/receipts', {
        method: 'POST',
        body: JSON.stringify({
          product_id: input.productId,
          location_id: input.locationId,
          qty: input.qty.trim(),
          // El costo de la entrada alimenta la valuación: sin él, el margen del
          // día siguiente sale inventado.
          unit_cost: input.unitCost.trim(),
          comment: input.comment?.trim() || null
        })
      })
    } finally {
      saving.value = false
    }
  }

  return {
    locations,
    rows,
    movements,
    expired,
    loading,
    saving,
    loadLocations,
    balances,
    loadMovements,
    loadExpiredLots,
    adjust,
    receive
  }
}
