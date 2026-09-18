import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * Configuración operativa del POS (D-12, D-06, B-09, Q-06).
 *
 * Solo lo que el negocio cambia de verdad. El resto de `cmn_settings` no se
 * expone: una pantalla con ciento cuarenta claves sueltas es el patrón de OSPOS
 * que D-12 vino a corregir.
 *
 * Las claves llevan punto (`cash.rounding_mode`) y viajan dentro de un objeto
 * `settings`, no como campos sueltos: para el validador de Laravel el punto es
 * un separador de camino, y una regla que nunca se aplica es peor que no
 * tenerla.
 */

export type RoundingMode = 'none' | 'nearest' | 'up' | 'down'

export interface PosSettings {
  'receipt.footer': string
  'receipt.notice': string
  'cash.rounding_mode': RoundingMode
  'cash.rounding_increment': string
  'tax.fixed_quota_regime': boolean
  'pos.offline_max_hours': number
  /** Propina (G-16): apagada por defecto, con un porcentaje sugerido. */
  'tip.enabled': boolean
  'tip.suggested_percent': string
}

/** Lo que viene de la instalación y no se edita desde la pantalla. */
export interface FixedSettings {
  base_currency: string
  secondary_currency: string
  business_profile: string
  costing_method: string
  require_shift: boolean
  refund_requires_original: boolean
  temporary_item_daily_limit: number
}

export interface Denomination {
  id: string
  currency_code: string
  value: string
  kind: 'bill' | 'coin'
  sort_order: number | null
  is_active: boolean
}

export interface ExchangeRate {
  currency_code: string
  rate: string
}

export function useSettings() {
  const values = ref<PosSettings | null>(null)
  const fixed = ref<FixedSettings | null>(null)
  const denominations = shallowRef<Denomination[]>([])
  const rate = ref<ExchangeRate | null>(null)
  const loading = ref(false)
  const saving = ref(false)

  async function load() {
    loading.value = true

    try {
      const data = await apiFetch<{ values: PosSettings, fixed: FixedSettings }>('/settings')
      values.value = data.values
      fixed.value = data.fixed
    } finally {
      loading.value = false
    }
  }

  async function save(changes: Partial<PosSettings>) {
    saving.value = true

    try {
      await apiFetch('/settings', {
        method: 'PUT',
        body: JSON.stringify({ settings: changes })
      })

      await load()
    } finally {
      saving.value = false
    }
  }

  async function loadDenominations(includeInactive = false) {
    const query = includeInactive ? '?include_inactive=1' : ''

    denominations.value = await apiGetList<Denomination>(`/cash/denominations/all${query}`)
  }

  async function addDenomination(input: { currency_code: string, value: string, kind: 'bill' | 'coin' }) {
    saving.value = true

    try {
      await apiFetch('/cash/denominations', { method: 'POST', body: JSON.stringify(input) })
    } finally {
      saving.value = false
    }
  }

  /** Baja lógica: los arqueos ya cerrados la referencian. */
  async function removeDenomination(id: string) {
    saving.value = true

    try {
      await apiFetch(`/cash/denominations/${id}`, { method: 'DELETE' })
    } finally {
      saving.value = false
    }
  }

  async function restoreDenomination(id: string) {
    saving.value = true

    try {
      await apiFetch(`/cash/denominations/${id}`, {
        method: 'PUT',
        body: JSON.stringify({ is_active: true })
      })
    } finally {
      saving.value = false
    }
  }

  /**
   * Tasa del dólar (Q-06).
   *
   * La carga el supervisor y **rige hasta que la cambie**, típicamente una vez
   * por semana. No hay consulta en línea: la red local puede no tener internet,
   * y una caja que no cobra en dólares por no alcanzar una API sería absurda.
   */
  async function loadRate(currency: string) {
    try {
      rate.value = await apiFetch<ExchangeRate>(`/exchange-rates?currency_code=${currency}`)
    } catch {
      // Sin tasa cargada todavía: es el estado de una instalación nueva, no un
      // fallo.
      rate.value = null
    }
  }

  async function saveRate(currency: string, value: string) {
    saving.value = true

    try {
      await apiFetch('/exchange-rates', {
        method: 'POST',
        body: JSON.stringify({ currency_code: currency, rate: value.trim() })
      })

      await loadRate(currency)
    } finally {
      saving.value = false
    }
  }

  return {
    values,
    fixed,
    denominations,
    rate,
    loading,
    saving,
    load,
    save,
    loadDenominations,
    addDenomination,
    removeDenomination,
    restoreDenomination,
    loadRate,
    saveRate
  }
}
