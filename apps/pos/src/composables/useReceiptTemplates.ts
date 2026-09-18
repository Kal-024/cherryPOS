import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * Plantillas de comprobante (D-11, H5).
 *
 * **El POS imprime el ticket de la venta; no emite el documento contable.** Esa
 * distinción es de arquitectura, no de estilo: la numeración seria, la
 * fiscalización y el asiento viven en el ERP, que ya los implementa. Lo que se
 * edita acá es cómo se ve el papel que se le entrega al cliente en el mostrador.
 *
 * La plantilla es **dato**, no veinte banderas en el código: `content.blocks` es
 * una lista ordenada y el servidor la convierte en líneas. Por eso el editor
 * necesita la **vista previa**: sin ella, configurar es teclear a ciegas y
 * descubrir el resultado en el primer cliente.
 */

export type BlockType =
  | 'text'
  | 'logo'
  | 'separator'
  | 'spacer'
  | 'field_list'
  | 'items'
  | 'totals'
  | 'payments'
  | 'taxes'
  | 'qr'

export interface TemplateBlock {
  type: BlockType
  content?: string
  align?: 'left' | 'center' | 'right'
  bold?: boolean
  size?: 'sm' | 'md'
  lines?: number
  fields?: { label: string, value: string }[]
  show?: string[]
  show_unit_price?: boolean
  show_discount?: boolean
  show_change?: boolean
  [key: string]: unknown
}

export type Paper = 'thermal_58' | 'thermal_80' | 'letter' | 'a4'

export interface ReceiptTemplate {
  id: string
  branch_id: string | null
  code: string
  name: string
  document_type: string
  paper: Paper
  content: { blocks: TemplateBlock[] }
  is_default: boolean
  /** Las del sistema son el respaldo: se duplican para editarlas. */
  is_system: boolean
  is_active: boolean
}

export interface Preview {
  paper: Paper
  /** Caracteres por línea. Manda sobre todo el diseño del ticket. */
  width: number
  lines: { text: string, align?: string, bold?: boolean, size?: string }[]
}

/** Anchos en caracteres, iguales a `ReceiptTemplate::WIDTHS` del servidor. */
export const PAPER_WIDTHS: Record<Paper, number> = {
  thermal_58: 32,
  thermal_80: 48,
  letter: 80,
  a4: 80
}

export const BLOCK_TYPES: BlockType[] = [
  'text',
  'logo',
  'separator',
  'spacer',
  'field_list',
  'items',
  'totals',
  'payments',
  'taxes',
  'qr'
]

/** Filas que el bloque de totales puede mostrar, en el orden en que se leen. */
export const TOTAL_ROWS = [
  'subtotal',
  'exempt_total',
  'discount_total',
  'taxes',
  'cash_rounding',
  'total'
]

export function newBlock(type: BlockType): TemplateBlock {
  switch (type) {
    case 'text':
      return { type, content: '', align: 'left' }
    case 'spacer':
      return { type, lines: 1 }
    case 'field_list':
      return { type, fields: [] }
    case 'items':
      return { type, show_unit_price: true, show_discount: true }
    case 'totals':
      return { type, show: ['subtotal', 'taxes', 'total'] }
    case 'payments':
      return { type, show_change: true }
    default:
      return { type }
  }
}

export function useReceiptTemplates() {
  const templates = shallowRef<ReceiptTemplate[]>([])
  const loading = ref(false)
  const saving = ref(false)

  async function list() {
    loading.value = true

    try {
      templates.value = await apiGetList<ReceiptTemplate>('/receipt-templates')
    } finally {
      loading.value = false
    }
  }

  async function find(id: string): Promise<ReceiptTemplate> {
    return apiFetch<ReceiptTemplate>(`/receipt-templates/${id}`)
  }

  async function update(id: string, changes: Partial<ReceiptTemplate>): Promise<ReceiptTemplate> {
    saving.value = true

    try {
      return await apiFetch<ReceiptTemplate>(`/receipt-templates/${id}`, {
        method: 'PUT',
        body: JSON.stringify(changes)
      })
    } finally {
      saving.value = false
    }
  }

  /**
   * Copia una plantilla del sistema a la sucursal.
   *
   * Es lo que permite personalizar sin perder el respaldo: la original queda
   * intacta y sirve de punto de retorno cuando alguien deja la suya
   * irreconocible.
   */
  async function duplicate(id: string): Promise<ReceiptTemplate> {
    saving.value = true

    try {
      return await apiFetch<ReceiptTemplate>(`/receipt-templates/${id}/duplicate`, { method: 'POST' })
    } finally {
      saving.value = false
    }
  }

  /** Vista previa con datos de ejemplo. Sin esto el editor no sirve. */
  async function preview(content: { blocks: TemplateBlock[] }, paper: Paper): Promise<Preview> {
    return apiFetch<Preview>('/receipt-templates/preview', {
      method: 'POST',
      body: JSON.stringify({ content, paper })
    })
  }

  return { templates, loading, saving, list, find, update, duplicate, preview }
}
