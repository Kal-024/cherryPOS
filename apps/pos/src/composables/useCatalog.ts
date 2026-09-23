import { computed, ref, shallowRef } from 'vue'
import { apiFetch, isApiError } from './httpClient'
import { STORES, all, get, put, putMany } from '../db/idb'

/**
 * Catálogo cacheado y búsqueda local (D-04, H1.7).
 *
 * OSPOS consulta al servidor **en cada tecla**. Con el catálogo en el cliente la
 * búsqueda es instantánea, el servidor deja de recibir una consulta por
 * pulsación y la caja sigue encontrando productos aunque la red parpadee.
 *
 * El ranking por frecuencia es la otra mitad de la decisión: *"dejar siempre a
 * simple vista los productos más buscados por el cajero, así siempre tendrá a
 * mano los más comunes"*. Se cuenta **por cajero**, porque el de la mañana y el
 * de la tarde no venden lo mismo.
 */

export interface CatalogProduct {
  id: string
  sku: string
  name: string
  price: string
  tracks_stock: boolean
  is_exempt: boolean
  uom?: { code: string, name: string } | null
  tax_code?: { code: string, rate: string } | null
  category?: { name: string } | null
  barcodes?: { code: string, embedded: string }[]
  /**
   * Dónde se prepara (F1-B). `null` = no va a comanda: una gaseosa de heladera
   * la sirve el mesero sin molestar a nadie.
   */
  prep_station?: string | null
  /**
   * Los grupos de modificadores viajan **con el catálogo** (B-06).
   *
   * Preguntar por ellos al tocar el plato sería justo la consulta por pulsación
   * que la caché local evita (D-04), y en un salón eso ocurre en cada pedido.
   */
  modifier_groups?: ModifierGroup[]
}

export interface ModifierGroup {
  id: string
  name: string
  /** Mayor que cero: obligatorio, y bloquea el envío a cocina. */
  min_select: number
  max_select: number | null
  modifiers: { id: string, name: string, price_delta: string, is_default: boolean }[]
}

interface SearchCandidate {
  product: CatalogProduct
  score: number
}

const products = shallowRef<CatalogProduct[]>([])
const usage = ref<Record<string, number>>({})
const syncing = ref(false)
const syncedAt = ref<string | null>(null)

/**
 * La bajada en curso, para poder esperarla.
 *
 * La caja arranca con el catálogo vacío y lo baja en segundo plano. Durante ese
 * rato la búsqueda local no encuentra nada, y lo que el cajero veía era **"no
 * hay ningún producto con ese código"** — una mentira, porque el producto existe
 * y solo está en camino. Con la promesa a mano, quien busca puede esperar a que
 * llegue en vez de recibir una negativa falsa.
 */
let pendingSync: Promise<boolean> | null = null
const loaded = ref(false)

/** Índice de búsqueda: texto normalizado por producto, calculado una vez. */
let haystack: { id: string, text: string, sku: string, codes: string[] }[] = []

/** La sucursal activa, para marcar de quién es el catálogo cacheado. */
function localBranchId(): string | null {
  try {
    const raw = localStorage.getItem('cherrypos.branch.info')

    return raw ? ((JSON.parse(raw) as { id?: string }).id ?? null) : null
  } catch {
    return null
  }
}

/** Sin tildes y en minúsculas: nadie escribe "Ñandú" con tilde al buscar. */
function normalize(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .trim()
}

function buildIndex(items: CatalogProduct[]) {
  haystack = items.map((product) => ({
    id: product.id,
    text: normalize(`${product.name} ${product.category?.name ?? ''}`),
    sku: normalize(product.sku),
    codes: (product.barcodes ?? []).map((barcode) => barcode.code)
  }))
}

async function loadFromDisk() {
  const [cached, counters, meta] = await Promise.all([
    all<CatalogProduct>(STORES.products),
    get<Record<string, number>>(STORES.usage, 'counts'),
    get<{ synced_at: string | null, branch_id?: string | null }>(STORES.meta, 'catalog')
  ])

  products.value = cached
  usage.value = counters ?? {}
  syncedAt.value = meta?.synced_at ?? null
  buildIndex(cached)
  loaded.value = true
}

export function useCatalog() {
  const count = computed(() => products.value.length)

  /** Lo que el cajero usa más, primero. Es el atajo de D-04. */
  const favourites = computed(() =>
    [...products.value]
      .filter((product) => (usage.value[product.id] ?? 0) > 0)
      .sort((a, b) => (usage.value[b.id] ?? 0) - (usage.value[a.id] ?? 0))
      .slice(0, 12)
  )

  async function ready() {
    if (!loaded.value) await loadFromDisk()
  }

  /**
   * Baja el catálogo y lo guarda.
   *
   * Se llama al abrir la caja y cuando el sondeo avisa que el catálogo cambió
   * (G-13) — no en cada venta. Bajar tres mil productos por cada ticket sería
   * tirar la red abajo por nada.
   */
  async function sync(): Promise<boolean> {
    // Una sola bajada a la vez, y quien llegue mientras tanto espera la misma.
    if (pendingSync) return pendingSync

    syncing.value = true
    pendingSync = run()

    return pendingSync
  }

  async function run(): Promise<boolean> {
    try {
      const items = await apiFetch<CatalogProduct[]>('/catalog/products?limit=10000')

      await putMany(
        STORES.products,
        items.map((product) => [product.id, product] as const)
      )

      products.value = items
      buildIndex(items)
      syncedAt.value = new Date().toISOString()
      // La sucursal queda anotada junto al catálogo: es **suyo**, no de la caja.
      // Dos terminales del mismo local lo comparten —cambiar entre ellas no
      // vuelve a bajar nada— y si el equipo pasa a otra sucursal, lo cacheado se
      // descarta en la activación.
      await put(STORES.meta, 'catalog', {
        synced_at: syncedAt.value,
        branch_id: localBranchId()
      })

      return true
    } catch (err) {
      // Un catálogo vacío en el servidor devuelve 404 (mismo contrato que
      // cherryF): no es un fallo, es que todavía no cargaron productos.
      if (isApiError(err) && err.status === 404) {
        return true
      }

      // Sin red se sigue con lo que había en disco. Eso es justamente lo que
      // compra tener el catálogo local.
      return false
    } finally {
      syncing.value = false
      pendingSync = null
    }
  }

  /**
   * Espera a tener catálogo con el que responder.
   *
   * Devuelve en cuanto hay productos en memoria. Si todavía no llegaron, espera
   * la bajada en curso —o la arranca— antes de contestar. Es la diferencia entre
   * "todavía no lo tengo" y "no existe", que para un cajero con un cliente
   * delante no es un matiz.
   */
  async function whenReady(): Promise<void> {
    await ready()

    if (products.value.length > 0) return

    await (pendingSync ?? sync())
  }

  /**
   * Búsqueda incremental.
   *
   * El orden no es alfabético sino **por utilidad**: primero lo que coincide
   * exacto con un código —lo que salió del lector—, después lo que empieza con
   * el término, y al final lo que lo contiene. Dentro de cada grupo pesa cuánto
   * lo usa este cajero.
   */
  function search(term: string, limit = 20): CatalogProduct[] {
    const needle = normalize(term)

    if (needle.length === 0) return favourites.value.slice(0, limit)

    const byId = new Map(products.value.map((product) => [product.id, product]))
    const candidates: SearchCandidate[] = []

    for (const entry of haystack) {
      let score = 0

      if (entry.codes.includes(term.trim())) {
        // Coincidencia exacta de código de barras: es lo que el lector acaba de
        // leer, y no hay nada que pueda ser mejor.
        score = 1000
      } else if (entry.sku === needle) {
        score = 900
      } else if (entry.sku.startsWith(needle)) {
        score = 700
      } else if (entry.text.startsWith(needle)) {
        score = 500
      } else if (entry.text.includes(needle)) {
        score = 300
      } else {
        continue
      }

      const product = byId.get(entry.id)
      if (!product) continue

      // La frecuencia desempata dentro del grupo, no entre grupos: un producto
      // muy vendido no debe ganarle a una coincidencia exacta de código.
      candidates.push({ product, score: score + Math.min(usage.value[entry.id] ?? 0, 99) })
    }

    return candidates
      .sort((a, b) => b.score - a.score)
      .slice(0, limit)
      .map((candidate) => candidate.product)
  }

  function byBarcode(code: string): CatalogProduct | null {
    const clean = code.trim()

    return products.value.find((product) =>
      (product.barcodes ?? []).some((barcode) => barcode.code === clean)
    ) ?? null
  }

  function byId(id: string): CatalogProduct | null {
    return products.value.find((product) => product.id === id) ?? null
  }

  /** Suma uno al contador del producto. Alimenta el ranking de D-04. */
  async function recordUse(productId: string) {
    usage.value = { ...usage.value, [productId]: (usage.value[productId] ?? 0) + 1 }
    // Copia plana: `usage.value` es un proxy de Vue y el clonado estructurado
    // de IndexedDB no sabe clonarlo. Sin esto el ranking de D-04 no se guardaba
    // —fallaba en silencio— y cada arranque empezaba de cero.
    await put(STORES.usage, 'counts', { ...usage.value })
  }

  return {
    products,
    count,
    favourites,
    syncing,
    syncedAt,
    loaded,
    ready,
    sync,
    whenReady,
    search,
    byBarcode,
    byId,
    recordUse
  }
}
