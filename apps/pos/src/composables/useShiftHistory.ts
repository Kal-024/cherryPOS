import { computed, ref, shallowRef } from 'vue'
import { apiDownload, apiFetch, apiGetList, apiOpen } from './httpClient'
import type { CurrencyCut, ShiftSummary } from './useShift'

/**
 * Las cajas ya cerradas (H4.3).
 *
 * `useShift` habla del turno **en curso**: es lo que la caja necesita para poder
 * vender. Esto es lo otro —lo que pasó— y vive aparte porque su lector no es el
 * cajero sino quien responde por la caja al final del día.
 *
 * **Por qué hacía falta.** Al cerrar aparecía el faltante o el sobrante y ahí
 * terminaba todo: el turno quedaba descuadrado para siempre y ninguna pantalla
 * volvía a mostrarlo. Eso pesa más allá del POS — el ERP no emite el comprobante
 * contable del día si la caja no cuadra, así que un descuadre sin resolver frena
 * la contabilidad entera.
 *
 * **Cuadrar no reescribe el arqueo** (P1). El conteo original queda tal como se
 * hizo y la diferencia se salda con un movimiento de caja: salida si faltó,
 * entrada si sobró, con su motivo y el PIN del supervisor. Como lo esperado en el
 * cajón ya suma los movimientos, la diferencia se va a cero por aritmética y no
 * porque alguien la haya tachado.
 */

export interface ClosedShift {
  id: string
  code: string
  terminal_id: string
  opened_at: string
  closed_at: string | null
  by_currency: CurrencyCut[]
  /** Sin diferencia en ninguna moneda. Se deduce de los números, no se guarda. */
  settled: boolean
}

export interface Settlement {
  id: string
  direction: 'in' | 'out'
  reason: string
  amount: string
  currency_code: string
  occurred_at: string
}

const shifts = shallowRef<ClosedShift[]>([])

export function useShiftHistory() {
  const loading = ref(false)
  const saving = ref(false)

  /** Las que todavía no cuadran: la bandeja que hay que vaciar. */
  const pending = computed(() => shifts.value.filter((shift) => !shift.settled))

  async function list(pendingOnly = false) {
    loading.value = true

    try {
      shifts.value = await apiGetList<ClosedShift>(`/shifts?pending_only=${pendingOnly ? '1' : '0'}`)
    } finally {
      loading.value = false
    }
  }

  /** El corte completo: esperado contra contado, por cajero y por denominación. */
  async function summary(id: string): Promise<ShiftSummary & { settled: boolean, settlements: Settlement[] }> {
    return apiFetch(`/shifts/${id}/summary`)
  }

  /**
   * Cuadra la caja.
   *
   * No se manda importe: es la diferencia que el propio corte calculó. Dejarlo a
   * mano abriría la puerta a "cuadrar" con un número redondo que no corresponde
   * a nada.
   */
  async function settle(id: string, reason: string, supervisorCode: string, supervisorPin: string) {
    saving.value = true

    try {
      return await apiFetch(`/shifts/${id}/settle`, {
        method: 'POST',
        body: JSON.stringify({
          reason,
          supervisor_code: supervisorCode,
          supervisor_pin: supervisorPin
        })
      })
    } finally {
      saving.value = false
    }
  }

  /** Se abre en una pestaña: el corte se entrega con el efectivo y se archiva. */
  async function openCut(id: string) {
    await apiOpen(`/shifts/${id}/cut/pdf`)
  }

  async function downloadCut(id: string, code: string) {
    await apiDownload(`/shifts/${id}/cut/pdf`, `corte-${code}.pdf`)
  }

  return { shifts, pending, loading, saving, list, summary, settle, openCut, downloadCut }
}
