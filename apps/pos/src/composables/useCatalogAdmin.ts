import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'
import type { CatalogProduct } from './useCatalog'

/**
 * Catálogo desde la trastienda: alta, edición y baja de productos.
 *
 * Deliberadamente **no** reutiliza la caché de `useCatalog`. Esa caché existe
 * para que el cajero busque sin red (D-04) y se refresca por sondeo; la
 * administración necesita lo contrario —lo que el servidor tiene ahora mismo,
 * incluidos los productos dados de baja, que la caja nunca debe ver—. Mezclar
 * las dos haría que dar de baja un producto lo dejara vendible hasta el
 * siguiente sondeo.
 */

export interface Category {
  id: string
  code: string
  name: string
  parent_id: string | null
  color: string | null
  sort_order: number | null
  is_active: boolean
}

export interface Uom {
  id: string
  code: string
  name: string
  decimals: number
}

export interface TaxCode {
  id: string
  code: string
  name: string
  rate: string
  type: string
  /** `gross` significa que el precio de góndola ya trae el impuesto dentro. */
  base: string
}

/** Lo que el formulario edita. Es un subconjunto de lo que la API acepta. */
export interface ProductDraft {
  sku: string
  name: string
  description: string
  category_id: string | null
  uom_id: string | null
  tax_code_id: string | null
  price: string
  cost: string
  is_exempt: boolean
  tracks_stock: boolean
  tracks_lots: boolean
  allow_negative_stock: boolean
  min_stock: string
  is_active: boolean
  attributes: Record<string, unknown>
}

/** Producto tal como lo devuelve la administración: con los campos que la caja no usa. */
export interface AdminProduct extends CatalogProduct {
  description: string | null
  category_id: string | null
  uom_id: string
  tax_code_id: string | null
  cost: string | null
  tracks_lots: boolean
  allow_negative_stock: boolean
  min_stock: string | null
  is_active: boolean
  attributes: Record<string, unknown> | null
}

/**
 * Con ERP integrado el catálogo es del ERP y el POS solo lo lee (§12.1).
 *
 * La pantalla lo consulta para no ofrecer un botón que el servidor va a
 * rechazar: un formulario que guarda y devuelve 422 es peor que uno que dice
 * desde el principio dónde se corrige.
 */
const erpOwned = ref(false)

const categories = shallowRef<Category[]>([])
const uoms = shallowRef<Uom[]>([])
const taxCodes = shallowRef<TaxCode[]>([])
const referencesLoaded = ref(false)

export function emptyDraft(): ProductDraft {
  return {
    sku: '',
    name: '',
    description: '',
    category_id: null,
    uom_id: null,
    tax_code_id: null,
    price: '',
    cost: '',
    is_exempt: false,
    // Por defecto el ítem es un producto: el caso raro es el servicio, y es el
    // que conviene que haya que marcar a mano.
    tracks_stock: true,
    tracks_lots: false,
    allow_negative_stock: false,
    min_stock: '',
    is_active: true,
    attributes: {}
  }
}

export function draftOf(product: AdminProduct): ProductDraft {
  return {
    sku: product.sku,
    name: product.name,
    description: product.description ?? '',
    category_id: product.category_id,
    uom_id: product.uom_id,
    tax_code_id: product.tax_code_id,
    price: product.price,
    cost: product.cost ?? '',
    is_exempt: product.is_exempt,
    tracks_stock: product.tracks_stock,
    tracks_lots: product.tracks_lots,
    allow_negative_stock: product.allow_negative_stock,
    min_stock: product.min_stock ?? '',
    is_active: product.is_active,
    attributes: product.attributes ?? {}
  }
}

export function useCatalogAdmin() {
  const products = shallowRef<AdminProduct[]>([])
  const loading = ref(false)
  const saving = ref(false)

  /** Categorías, unidades e impuestos: se piden una vez por sesión de pantalla. */
  async function checkOwnership() {
    try {
      const status = await apiFetch<{ configured: boolean }>('/erp/status')
      erpOwned.value = status.configured
    } catch {
      // Sin permiso para ver la integración o sin respuesta: se asume que el
      // catálogo es del POS, que es el caso de una instalación sin ERP.
      erpOwned.value = false
    }
  }

  async function loadReferences(force = false) {
    if (referencesLoaded.value && !force) return

    const [categoryList, uomList, taxList] = await Promise.all([
      apiGetList<Category>('/catalog/categories'),
      apiGetList<Uom>('/catalog/uoms'),
      apiGetList<TaxCode>('/catalog/tax-codes')
    ])

    categories.value = categoryList
    uoms.value = uomList
    taxCodes.value = taxList
    referencesLoaded.value = true
  }

  /**
   * El filtro lo resuelve el servidor, no el navegador.
   *
   * Es lo contrario de la caja —allí la búsqueda es local a propósito— porque
   * la administración necesita ver también lo dado de baja, y bajarse el
   * catálogo completo para filtrarlo en memoria sería pagar el precio de la
   * caché sin ninguno de sus beneficios.
   */
  async function list(options: { search?: string, categoryId?: string | null, includeInactive?: boolean } = {}) {
    loading.value = true

    try {
      const query = new URLSearchParams()
      if (options.search) query.set('search', options.search)
      if (options.categoryId) query.set('category_id', options.categoryId)
      if (options.includeInactive) query.set('include_inactive', '1')
      query.set('limit', '200')

      products.value = await apiGetList<AdminProduct>(`/catalog/products?${query.toString()}`)
    } finally {
      loading.value = false
    }
  }

  async function find(id: string): Promise<AdminProduct> {
    return apiFetch<AdminProduct>(`/catalog/products/${id}`)
  }

  async function create(draft: ProductDraft): Promise<AdminProduct> {
    saving.value = true

    try {
      return await apiFetch<AdminProduct>('/catalog/products', {
        method: 'POST',
        body: JSON.stringify(payload(draft))
      })
    } finally {
      saving.value = false
    }
  }

  async function update(id: string, draft: ProductDraft): Promise<AdminProduct> {
    saving.value = true

    try {
      return await apiFetch<AdminProduct>(`/catalog/products/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload(draft))
      })
    } finally {
      saving.value = false
    }
  }

  /** Baja lógica: la API nunca borra un producto que el histórico referencia. */
  async function deactivate(id: string) {
    await apiFetch(`/catalog/products/${id}`, { method: 'DELETE' })
  }

  async function createCategory(input: { code: string, name: string, color?: string | null }) {
    const category = await apiFetch<Category>('/catalog/categories', {
      method: 'POST',
      body: JSON.stringify(input)
    })

    categories.value = [...categories.value, category]

    return category
  }

  return {
    products,
    erpOwned,
    checkOwnership,
    categories,
    uoms,
    taxCodes,
    loading,
    saving,
    loadReferences,
    list,
    find,
    create,
    update,
    deactivate,
    createCategory
  }
}

/**
 * El campo vacío viaja como `null`, no como `""`.
 *
 * Laravel valida `nullable|numeric` y una cadena vacía no es numérica: sin esta
 * limpieza, dejar el costo en blanco devolvería un 422 que el supervisor no
 * puede interpretar.
 */
function payload(draft: ProductDraft): Record<string, unknown> {
  const blank = (value: string) => (value.trim() === '' ? null : value.trim())

  return {
    ...draft,
    description: blank(draft.description),
    cost: blank(draft.cost),
    min_stock: blank(draft.min_stock),
    price: draft.price.trim()
  }
}
