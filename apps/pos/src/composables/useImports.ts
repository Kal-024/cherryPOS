import { ref, shallowRef } from 'vue'
import { apiDownload, apiFetch } from './httpClient'

/**
 * Importación y exportación masiva (D-15, H1.6).
 *
 * **Sin importación no hay puesta en marcha**: nadie carga tres mil productos a
 * mano. El flujo tiene tres pasos y el del medio es el que OSPOS no tiene:
 * previsualizar, **mirar**, aplicar. Y un cuarto por si acaso: revertir.
 *
 * La previsualización **no escribe nada**. Es lo que convierte "subí el archivo
 * y rezá" en "mirá qué va a pasar", y por eso la pantalla obliga a pasar por
 * ella aunque el archivo venga de la exportación de ayer.
 */

export type ImportKind = 'products' | 'customers' | 'suppliers' | 'stock'

export interface ColumnSpec {
  required: boolean
  label: string
}

export type KindCatalog = Record<string, Record<string, ColumnSpec>>

export interface ImportProblem {
  row_number: number
  errors: string[]
  raw: Record<string, unknown>
}

export interface ImportSample {
  row_number: number
  action: string
  normalized: Record<string, unknown>
}

export interface ImportRow {
  row_number: number
  action: string
  errors: string[] | null
  raw: Record<string, unknown>
  normalized: Record<string, unknown> | null
  entity_id: string | null
  applied: boolean
  reverted: boolean
  revert_error: string | null
}

export interface ImportBatch {
  id: string
  kind: ImportKind
  filename: string
  status: string
  rows_total: number
  rows_valid: number
  rows_invalid: number
  rows_applied: number
  applied_at: string | null
  reverted_at: string | null
  problems?: ImportProblem[]
  sample?: ImportSample[]
  rows?: ImportRow[]
}

export function useImports() {
  const kinds = ref<KindCatalog>({})
  const batch = shallowRef<ImportBatch | null>(null)
  const loading = ref(false)
  const working = ref(false)

  async function loadKinds() {
    kinds.value = await apiFetch<KindCatalog>('/imports/kinds')
  }

  /** Lee el archivo y no escribe nada. Devuelve qué pasaría. */
  async function preview(kind: ImportKind, file: File) {
    loading.value = true

    try {
      const body = new FormData()
      body.append('file', file)

      batch.value = await apiFetch<ImportBatch>(`/imports/${kind}/preview`, { method: 'POST', body })

      return batch.value
    } finally {
      loading.value = false
    }
  }

  async function apply() {
    if (!batch.value) return

    working.value = true

    try {
      batch.value = await apiFetch<ImportBatch>(`/imports/${batch.value.id}/apply`, { method: 'POST' })
    } finally {
      working.value = false
    }
  }

  /**
   * Deshace lo aplicado.
   *
   * No siempre se puede del todo —un producto que desde entonces se vendió ya
   * no se borra— y el servidor lo dice fila por fila en `revert_error`. Ocultar
   * esos casos haría creer que el archivo quedó como antes cuando no es así.
   */
  async function revert() {
    if (!batch.value) return

    working.value = true

    try {
      batch.value = await apiFetch<ImportBatch>(`/imports/${batch.value.id}/revert`, { method: 'POST' })
    } finally {
      working.value = false
    }
  }

  async function reload() {
    if (!batch.value) return

    batch.value = await apiFetch<ImportBatch>(`/imports/${batch.value.id}`)
  }

  /** Plantilla vacía: pedirle al cliente que adivine los encabezados no funciona. */
  async function downloadTemplate(kind: ImportKind) {
    await apiDownload(`/imports/templates/${kind}`, `plantilla-${kind}.xlsx`)
  }

  /**
   * Los datos actuales en el **mismo formato que se importa**.
   *
   * Bajar, corregir en Excel y volver a subir es la forma real de editar mil
   * productos; una pantalla con mil filas editables no lo es.
   */
  async function downloadExport(kind: ImportKind) {
    const stamp = new Date().toISOString().slice(0, 10)

    await apiDownload(`/exports/${kind}`, `${kind}-${stamp}.xlsx`)
  }

  function reset() {
    batch.value = null
  }

  return {
    kinds,
    batch,
    loading,
    working,
    loadKinds,
    preview,
    apply,
    revert,
    reload,
    downloadTemplate,
    downloadExport,
    reset
  }
}
