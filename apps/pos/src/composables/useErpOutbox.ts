import { computed, ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * Bandeja de envíos al ERP (P4, F1-C).
 *
 * **El POS nunca depende del ERP para cerrar una venta**: el ticket se cobra y
 * se cierra, y el envío va después. Lo que se ve acá es lo que quedó en camino
 * y lo que el ERP rechazó.
 *
 * La distinción que gobierna la pantalla: hay rechazos que **se arreglan del
 * otro lado** y se reintentan, y hay rechazos que el ERP no va a aceptar nunca
 * —una tarjeta de regalo mientras A3 siga abierto—. Esos se cierran a mano, con
 * motivo, y no se reintentan: reintentar no cambia la respuesta.
 */

export interface OutboxEntry {
  id: string
  sale_id: string
  sale_number: string | null
  sale_total: string | null
  closed_at: string | null
  status: 'pending' | 'sending' | 'sent' | 'exception'
  attempts: number
  next_attempt_at: string | null
  http_status: number | null
  error_code: string | null
  error_message: string | null
  erp_document_number: string | null
  duplicate: boolean
  /** Distinto de cero es **alarma**: los dos motores están divergiendo. */
  tax_difference: string | null
  sent_at: string | null
  resolved_at: string | null
  payload?: Record<string, unknown>
}

export interface ErpStatus {
  reconciliation_pending?: number
  masters_synced_at?: string | null
  /** Sin ERP configurado el POS opera solo: es un estado válido, no un error. */
  configured: boolean
  base_url: string | null
  company_id: string | number | null
  settings_synced_at: string | null
  queue: { pending: number, exceptions: number, sent_today: number }
  /** Lo que rige hoy, venga del ERP o de la configuración local. */
  effective: {
    offline_max_hours: number
    offline_pin_mode: string
    require_shift: boolean
    layout_profile: string
  }
}

export interface ReconciliationEntry {
  id: string
  kind: 'product' | 'customer'
  entity_id: string | null
  label: string
  natural_key: string | null
  /** `no_match`, `conflict` o `no_key`: por qué espera decisión humana. */
  reason: string
  detail: Record<string, unknown> | null
  resolved_at: string | null
}

export function useErpOutbox() {
  const entries = shallowRef<OutboxEntry[]>([])
  const loading = ref(false)
  const saving = ref(false)

  const exceptions = computed(() => entries.value.filter((entry) => entry.status === 'exception'))
  const divergences = computed(() =>
    entries.value.filter((entry) => entry.tax_difference !== null && Number.parseFloat(entry.tax_difference) !== 0)
  )

  async function list(options: { status?: string | null, withDifference?: boolean, unresolved?: boolean } = {}) {
    loading.value = true

    try {
      const query = new URLSearchParams()
      if (options.status) query.set('status', options.status)
      if (options.withDifference) query.set('with_difference', '1')
      query.set('unresolved', options.unresolved === false ? '0' : '1')

      entries.value = await apiGetList<OutboxEntry>(`/erp/outbox?${query.toString()}`)
    } finally {
      loading.value = false
    }
  }

  /** El sobre completo, para poder explicar el rechazo. */
  async function find(id: string): Promise<OutboxEntry> {
    return apiFetch<OutboxEntry>(`/erp/outbox/${id}`)
  }

  async function retry(id: string) {
    saving.value = true

    try {
      await apiFetch(`/erp/outbox/${id}/retry`, { method: 'POST' })
    } finally {
      saving.value = false
    }
  }

  /** Cerrar no borra: la entrada queda con su error y con quién la cerró. */
  async function resolve(id: string, reason: string) {
    saving.value = true

    try {
      await apiFetch(`/erp/outbox/${id}/resolve`, {
        method: 'POST',
        body: JSON.stringify({ reason: reason.trim() })
      })
    } finally {
      saving.value = false
    }
  }

  const status = ref<ErpStatus | null>(null)
  const reconciliation = shallowRef<ReconciliationEntry[]>([])

  async function loadStatus() {
    status.value = await apiFetch<ErpStatus>('/erp/status')
  }

  /**
   * Baja la configuración del ERP.
   *
   * Es una lectura: con ERP presente la configuración de caja es suya y el POS
   * la adopta (P2, P3). El POS nunca le escribe encima.
   */
  async function pullSettings() {
    saving.value = true

    try {
      const result = await apiFetch<{ applied: Record<string, unknown> }>('/erp/settings/pull', { method: 'POST' })
      await loadStatus()

      return result.applied
    } finally {
      saving.value = false
    }
  }

  /** Lo que espera decisión humana: no bloquea nada, solo avisa (§12.8). */
  async function loadReconciliation() {
    reconciliation.value = await apiGetList<ReconciliationEntry>('/erp/reconciliation')
  }

  /**
   * Lee catálogo y clientes del ERP.
   *
   * Es incremental tras la primera corrida: solo viaja lo que cambió desde la
   * marca que devolvió el ERP.
   */
  async function pullMasters() {
    saving.value = true

    try {
      const result = await apiFetch<{
        products: Record<string, number>
        customers: Record<string, number>
      }>('/erp/masters/pull', { method: 'POST' })

      await Promise.all([loadStatus(), loadReconciliation().catch(() => undefined)])

      return result
    } finally {
      saving.value = false
    }
  }

  /**
   * Cierra una fila de la bandeja.
   *
   * No fusiona: fusionar por nombre es justo lo que Q-04 prohíbe. Deja
   * constancia de que alguien la miró y qué decidió.
   */
  async function resolveReconciliation(id: string, resolution: string) {
    saving.value = true

    try {
      await apiFetch(`/erp/reconciliation/${id}/resolve`, {
        method: 'POST',
        body: JSON.stringify({ resolution: resolution.trim() })
      })

      await loadReconciliation().catch(() => undefined)
    } finally {
      saving.value = false
    }
  }

  return {
    entries,
    exceptions,
    divergences,
    status,
    reconciliation,
    loading,
    saving,
    list,
    find,
    retry,
    resolve,
    loadStatus,
    pullSettings,
    loadReconciliation,
    pullMasters,
    resolveReconciliation
  }
}
