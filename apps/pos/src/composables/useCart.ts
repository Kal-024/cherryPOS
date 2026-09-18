import { computed, ref, shallowRef } from 'vue'
import { apiFetch, firstApiErrorMessage, isApiError } from './httpClient'
import { useCatalog, type CatalogProduct } from './useCatalog'
import { useOffline, type DraftLine, type DraftPayment } from './useOffline'
import { STORES, put, remove } from '../db/idb'
import { scoped } from '../db/scope'
import { uuid7 } from '../utils/uuid'

/**
 * El carrito (D-21, H2.5).
 *
 * En OSPOS el carrito vive en la sesión HTTP: ninguna venta puede continuarse en
 * otro dispositivo y ningún proceso puede validarla. Aquí es **una venta con
 * identidad propia en el servidor, más una copia local**.
 *
 * La copia local no es una caché de rendimiento: es lo que hace que cerrar el
 * navegador —o que se corte la luz del monitor— no pierda la venta en curso. Al
 * volver, el terminal recupera el identificador de disco y vuelve a pedirle la
 * venta al servidor.
 *
 * **El identificador lo genera el terminal**, antes de tocar la red. Esa es la
 * precondición que habilita el modo degradado de H6 y la idempotencia del ERP.
 */

export interface SaleLine {
  id: string
  sequence: number
  description: string
  kind: 'product' | 'temporary' | 'amount' | 'instrument'
  qty: string
  unit_price: string
  line_discount: string
  sale_discount_share: string
  gross: string
  taxable_base: string
  tax_total: string
  total: string
  group_name: string | null
  product_id: string | null
  /** Curso del servicio (G-16). 1 en todo lo que no sea un salón con cursos. */
  course?: number
  notes?: string | null
}

export interface SalePayment {
  id: string
  method: 'cash' | 'card' | 'credit' | 'transfer' | 'other'
  currency_code: string
  amount: string
  amount_base: string
  exchange_rate: string | null
  is_change: boolean
  reference: string | null
}

export interface Sale {
  id: string
  number: string | null
  status: 'draft' | 'suspended' | 'completed' | 'voided'
  sale_type: string
  label: string | null
  currency_code: string
  gross: string
  line_discount_total: string
  sale_discount: string
  discount_total: string
  subtotal: string
  taxable_base: string
  exempt_total: string
  tax_total: string
  total: string
  cash_rounding: string
  /** Propina (G-16): se cobra con la cuenta y queda fuera del total fiscal. */
  tip_amount: string
  paid: string
  balance: string
  customer_id: string | null
  /** Cuenta de salón (F1-B): la mesa que ocupa esta venta suspendida. */
  dining_table_id?: string | null
  guests?: number | null
  lines?: SaleLine[]
  payments?: SalePayment[]
}

const sale = shallowRef<Sale | null>(null)
const busy = ref(false)
const lastError = ref('')

/**
 * Sin servidor.
 *
 * Lo enciende la pantalla cuando el sondeo deja de contestar. A partir de ahí el
 * carrito vive en memoria y los totales los calcula el motor de
 * `packages/calc` — el mismo que el CI verifica contra su gemelo en PHP.
 *
 * La pantalla **no cambia**: llama a los mismos métodos. Que el servidor esté o
 * no es asunto del carrito, no de la interfaz.
 */
const degraded = ref(false)
const localLines = ref<DraftLine[]>([])
const localPayments = ref<DraftPayment[]>([])
const localTip = ref('0.00')

/**
 * Dónde queda anotada la venta en curso, para recuperarla tras un cierre.
 *
 * Con el dueño en la clave (`t:<terminal>:current`): la venta a medias es de la
 * caja donde se empezó, y sin eso la del salón recuperaba el carrito del
 * mostrador al activarse en el mismo equipo.
 */
const CURRENT_KEY = 'current'

async function rememberLocally(current: Sale | null) {
  if (current === null) {
    await remove(STORES.carts, scoped(CURRENT_KEY))

    return
  }

  await put(STORES.carts, scoped(CURRENT_KEY), { id: current.id, saved_at: new Date().toISOString() })
}

export function useCart() {
  const { recordUse } = useCatalog()
  const offline = useOffline()

  /**
   * Arma una venta con la forma que esperan los componentes, a partir del
   * cálculo local.
   *
   * Es una traducción, no un modelo paralelo: las mismas claves que devuelve el
   * servidor, para que la pantalla no distinga un modo del otro.
   */
  function buildLocalSale(): Sale {
    const result = offline.calculate(localLines.value, localPayments.value, false, localTip.value)

    return {
      id: sale.value?.id ?? uuid7(),
      number: null,
      status: 'draft',
      sale_type: 'counter',
      label: null,
      currency_code: offline.settings.value?.base_currency ?? 'NIO',
      gross: result.gross,
      line_discount_total: result.lineDiscountTotal,
      sale_discount: result.saleDiscount,
      discount_total: result.discountTotal,
      subtotal: result.subtotal,
      taxable_base: result.taxableBase,
      exempt_total: result.exemptTotal,
      tax_total: result.taxTotal,
      total: result.total,
      cash_rounding: result.cashRounding,
      tip_amount: result.tip,
      paid: result.paid,
      balance: result.balance,
      customer_id: null,
      lines: result.lines.map((line, index) => ({
        id: String(index),
        sequence: index + 1,
        description: localLines.value[index]?.description ?? '',
        kind: localLines.value[index]?.kind ?? 'product',
        qty: localLines.value[index]?.qty ?? '0',
        unit_price: localLines.value[index]?.unit_price ?? '0',
        line_discount: line.lineDiscount,
        sale_discount_share: line.saleDiscountShare,
        gross: line.gross,
        taxable_base: line.taxableBase,
        tax_total: line.taxTotal,
        total: line.total,
        group_name: null,
        product_id: localLines.value[index]?.product_id ?? null
      })),
      payments: [
        ...localPayments.value.map((payment, index) => ({
          id: `p${index}`,
          method: payment.method,
          currency_code: payment.currency_code ?? 'NIO',
          amount: payment.amount,
          amount_base: payment.amount,
          exchange_rate: payment.exchange_rate ?? null,
          is_change: false,
          reference: null
        })),
        ...(Number(result.change) > 0
          ? [{
              id: 'change',
              method: 'cash' as const,
              currency_code: offline.settings.value?.base_currency ?? 'NIO',
              amount: `-${result.change}`,
              amount_base: `-${result.change}`,
              exchange_rate: null,
              is_change: true,
              reference: null
            }]
          : [])
      ]
    }
  }

  function refreshLocal() {
    sale.value = buildLocalSale()
  }

  const isOpen = computed(() => sale.value !== null && sale.value.status !== 'completed')
  const lines = computed(() => sale.value?.lines ?? [])
  const payments = computed(() => (sale.value?.payments ?? []).filter((p) => !p.is_change))
  const change = computed(() => {
    const entry = (sale.value?.payments ?? []).find((p) => p.is_change)

    return entry ? entry.amount.replace('-', '') : '0.00'
  })

  function adopt(next: Sale | null) {
    sale.value = next
    void rememberLocally(next)
  }

  /** Recarga la venta completa: el servidor es el que manda sobre los totales. */
  async function refresh() {
    if (!sale.value) return

    adopt(await apiFetch<Sale>(`/sales/${sale.value.id}`))
  }

  async function start(payload: Record<string, unknown> = {}) {
    busy.value = true

    try {
      // El identificador se genera acá, antes de tocar la red.
      const created = await apiFetch<Sale>('/sales', {
        method: 'POST',
        body: JSON.stringify({ id: uuid7(), ...payload })
      })

      adopt({ ...created, lines: [], payments: [] })

      return created
    } finally {
      busy.value = false
    }
  }

  async function ensure(): Promise<Sale> {
    if (sale.value && sale.value.status !== 'completed') return sale.value

    return start()
  }

  /**
   * Agrega una línea sin servidor.
   *
   * El producto sale del catálogo cacheado: es lo que `useCatalog` bajó al abrir
   * la caja, y la razón por la que la búsqueda sigue funcionando (D-04).
   */
  function addLocalLine(product: CatalogProduct, qty = '1') {
    localLines.value = [
      ...localLines.value,
      {
        product_id: product.id,
        description: product.name,
        kind: 'product',
        qty,
        unit_price: product.price,
        tax_code_id: (product as CatalogProduct & { tax_code_id?: string }).tax_code_id ?? null,
        is_exempt: product.is_exempt
      }
    ]

    void recordUse(product.id)
    refreshLocal()
  }

  /** @param payload lo que el backend espera en `POST /sales/{id}/lines` */
  async function addLine(payload: Record<string, unknown>) {
    const current = await ensure()
    busy.value = true
    lastError.value = ''

    try {
      const result = await apiFetch<{ lines: SaleLine[], sale: Sale }>(
        `/sales/${current.id}/lines`,
        { method: 'POST', body: JSON.stringify(payload) }
      )

      // El ranking de D-04 se alimenta de lo que el cajero realmente vende, no
      // de lo que busca: buscar y no agregar no dice nada.
      for (const line of result.lines) {
        if (line.product_id) void recordUse(line.product_id)
      }

      await refresh()

      return result.lines
    } catch (err) {
      lastError.value = firstApiErrorMessage(err)
      throw err
    } finally {
      busy.value = false
    }
  }

  async function updateLine(lineId: string, changes: Record<string, unknown>) {
    if (!sale.value) return

    busy.value = true

    try {
      await apiFetch(`/sales/${sale.value.id}/lines/${lineId}`, {
        method: 'PUT',
        body: JSON.stringify(changes)
      })
      await refresh()
    } finally {
      busy.value = false
    }
  }

  /**
   * Cambia la cantidad de una línea.
   *
   * Es la corrección más frecuente de una caja —el cliente lleva tres, no uno—
   * y por eso tiene camino propio en vez de pasar por `updateLine` con un
   * objeto suelto: sin servidor también hay que poder hacerla, y ahí la línea
   * vive en memoria.
   *
   * Cantidad cero o negativa **no borra la línea**: quitar tiene su propio
   * gesto, y un cero tecleado de más no puede hacer desaparecer lo que el
   * cliente ya puso en el mostrador.
   */
  async function setQty(lineId: string, qty: string) {
    const value = Number(qty)

    if (!Number.isFinite(value) || value <= 0) return

    if (degraded.value) {
      localLines.value = localLines.value.map((line, index) =>
        String(index) === lineId ? { ...line, qty } : line
      )
      refreshLocal()

      return
    }

    await updateLine(lineId, { qty })
  }

  /** Sube o baja de a uno. Es lo que hacen `+` y `−` en el teclado. */
  async function bumpQty(lineId: string, delta: number) {
    const line = lines.value.find((entry) => entry.id === lineId)

    if (!line) return

    // Redondeo a tres decimales: un producto por peso lleva 0,847 kg y sumarle
    // uno con aritmética de coma flotante daría 1,8470000000000002.
    const next = Math.round((Number(line.qty) + delta) * 1000) / 1000

    await setQty(lineId, String(next))
  }

  async function removeLine(lineId: string) {
    if (degraded.value) {
      localLines.value = localLines.value.filter((_, index) => String(index) !== lineId)
      refreshLocal()

      return
    }

    if (!sale.value) return

    busy.value = true

    try {
      await apiFetch(`/sales/${sale.value.id}/lines/${lineId}`, { method: 'DELETE' })
      await refresh()
    } finally {
      busy.value = false
    }
  }

  async function addPayment(payload: Record<string, unknown>) {
    if (degraded.value) {
      localPayments.value = [
        ...localPayments.value,
        {
          method: payload.method as DraftPayment['method'],
          amount: String(payload.amount),
          currency_code: payload.currency_code as string | undefined
        }
      ]
      refreshLocal()

      return
    }

    if (!sale.value) return

    busy.value = true
    lastError.value = ''

    try {
      await apiFetch(`/sales/${sale.value.id}/payments`, {
        method: 'POST',
        body: JSON.stringify(payload)
      })
      await refresh()
    } catch (err) {
      lastError.value = firstApiErrorMessage(err)
      throw err
    } finally {
      busy.value = false
    }
  }

  async function removePayment(paymentId: string) {
    if (degraded.value) {
      localPayments.value = localPayments.value.filter((_, index) => `p${index}` !== paymentId)
      refreshLocal()

      return
    }

    if (!sale.value) return

    await apiFetch(`/sales/${sale.value.id}/payments/${paymentId}`, { method: 'DELETE' })
    await refresh()
  }

  /**
   * Anota la propina de la cuenta (G-16).
   *
   * Sin servidor se queda en memoria y entra al motor local, igual que las
   * líneas: la mesa que paga durante un corte deja propina como cualquier otra.
   */
  async function setTip(amount: string, employeeId?: string) {
    if (degraded.value) {
      localTip.value = amount
      refreshLocal()

      return
    }

    if (!sale.value) return

    busy.value = true
    lastError.value = ''

    try {
      await apiFetch(`/sales/${sale.value.id}/tip`, {
        method: 'POST',
        body: JSON.stringify({ amount, ...(employeeId ? { employee_id: employeeId } : {}) })
      })
      await refresh()
    } catch (err) {
      lastError.value = firstApiErrorMessage(err)
      throw err
    } finally {
      busy.value = false
    }
  }

  async function setDiscount(payload: Record<string, unknown>) {
    if (!sale.value) return

    busy.value = true
    lastError.value = ''

    try {
      await apiFetch(`/sales/${sale.value.id}/discount`, {
        method: 'POST',
        body: JSON.stringify(payload)
      })
      await refresh()
    } catch (err) {
      lastError.value = firstApiErrorMessage(err)
      throw err
    } finally {
      busy.value = false
    }
  }

  /** Suspender es un cambio de estado, no una copia (D-01). */
  async function suspend(label?: string) {
    if (!sale.value) return

    await apiFetch(`/sales/${sale.value.id}/suspend`, {
      method: 'POST',
      body: JSON.stringify({ label: label ?? '' })
    })

    adopt(null)
  }

  /**
   * Traspasa líneas a otra cuenta, o la cuenta entera a otra mesa (F1-B).
   *
   * Lo que ya salió a cocina **no** se reescribe: la línea cambia de cuenta, su
   * comanda no. Eso lo garantiza el servidor; acá solo se pide.
   */
  async function transfer(target: { saleId?: string, tableId?: string }, lineIds: string[] = []) {
    const current = sale.value

    if (!current) return

    busy.value = true
    lastError.value = ''

    try {
      const result = await apiFetch<{ sale: Sale }>(`/sales/${current.id}/transfer`, {
        method: 'POST',
        body: JSON.stringify({
          target_sale_id: target.saleId ?? null,
          dining_table_id: target.tableId ?? null,
          lines: lineIds
        })
      })

      adopt(result.sale)
      await refresh()
    } catch (err) {
      lastError.value = firstApiErrorMessage(err)
      throw err
    } finally {
      busy.value = false
    }
  }

  /** Divide: lo elegido se va a una cuenta nueva **en la misma mesa**. */
  async function split(lineIds: string[]) {
    const current = sale.value

    if (!current) return null

    busy.value = true
    lastError.value = ''

    try {
      const result = await apiFetch<{ sale: Sale, target: Sale }>(`/sales/${current.id}/split`, {
        method: 'POST',
        body: JSON.stringify({ lines: lineIds })
      })

      adopt(result.sale)
      await refresh()

      return result.target
    } catch (err) {
      lastError.value = firstApiErrorMessage(err)
      throw err
    } finally {
      busy.value = false
    }
  }

  async function resume(saleId: string) {
    const resumed = await apiFetch<Sale>(`/sales/${saleId}/resume`, { method: 'POST' })

    adopt(resumed)
  }

  async function close(branchCode = '001', employeeId = '') {
    if (degraded.value) {
      // El ticket queda en la bandeja de salida con su número reservado. El
      // dinero ya está en el cajón: el servidor, cuando vuelva, lo registra.
      const ticket = await offline.closeOffline(
        branchCode,
        employeeId,
        localLines.value,
        localPayments.value,
        localTip.value
      )

      const closed = { ...buildLocalSale(), id: ticket.id, number: ticket.number, status: 'completed' as const }

      localLines.value = []
      localPayments.value = []
      localTip.value = '0.00'
      adopt(null)

      return closed
    }

    if (!sale.value) return null

    busy.value = true
    lastError.value = ''

    try {
      const closed = await apiFetch<Sale>(`/sales/${sale.value.id}/close`, { method: 'POST' })

      // La venta cerrada deja de ser el carrito: a partir de acá es inmutable.
      adopt(null)

      return closed
    } catch (err) {
      lastError.value = firstApiErrorMessage(err)
      throw err
    } finally {
      busy.value = false
    }
  }

  function discard() {
    localLines.value = []
    localPayments.value = []
    localTip.value = '0.00'
    adopt(null)
  }

  /**
   * Recupera la venta que quedó a medias.
   *
   * Es el criterio de aceptación de H2.5: cerrar el navegador y encontrar el
   * carrito donde estaba.
   */
  async function recover(savedId: string | null) {
    if (!savedId) return

    try {
      const recovered = await apiFetch<Sale>(`/sales/${savedId}`)

      // Si mientras tanto se cerró o la retomó otra caja, no se reabre: sería
      // resucitar una venta que ya no es de nadie.
      if (recovered.status === 'draft') {
        adopt(recovered)
      } else {
        await rememberLocally(null)
      }
    } catch (err) {
      if (isApiError(err) && err.status === 404) {
        await rememberLocally(null)
      }
    }
  }

  return {
    sale,
    lines,
    payments,
    change,
    busy,
    lastError,
    isOpen,
    degraded,
    addLocalLine,
    start,
    ensure,
    addLine,
    updateLine,
    setQty,
    bumpQty,
    removeLine,
    addPayment,
    removePayment,
    setDiscount,
    setTip,
    suspend,
    transfer,
    split,
    resume,
    close,
    discard,
    refresh,
    recover
  }
}
