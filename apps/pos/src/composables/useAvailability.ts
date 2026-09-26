import { computed, ref, shallowRef } from 'vue'
import { apiFetch } from './httpClient'

/**
 * La lista 86: lo que se acabó hoy (F1-B).
 *
 * Del argot de cocina —*«86 the salmon»*, dejen de venderlo—. El que está en la
 * cocina marca que se acabó y el plato sale **atenuado** en la pantalla del
 * mesero, que deja de poder pedirlo.
 *
 * **Vive aparte del catálogo cacheado, y a propósito.** El catálogo es dato
 * maestro: se guarda en IndexedDB y se relee pocas veces (D-04). Esto cambia en
 * medio del servicio, así que viaja como una lista de identificadores que se
 * sondea —barata de pedir, barata de comparar— en vez de obligar a rebajar el
 * catálogo entero cada vez que se acaba un plato.
 *
 * **El candado de verdad está en el servidor.** Acá se atenúa para que nadie
 * pierda tiempo pidiendo lo que no hay; que la línea no entre lo decide
 * `CartService`, porque la pantalla se puede saltar: el lector de códigos, un
 * ticket del modo degradado, dos meseros pidiendo el último a la vez.
 *
 * Sin servidor queda la última lista conocida, que es lo mejor disponible: el
 * modo degradado no puede saber qué se acabó mientras estuvo desconectado.
 */

/** Cada cuánto se relee. Quince segundos: se acaba un plato, no se cae la red. */
const POLL_MS = 15_000

const ids = shallowRef<string[]>([])
const polling = ref<ReturnType<typeof setInterval> | null>(null)

export function useAvailability() {
  const saving = ref(false)

  const unavailable = computed(() => new Set(ids.value))

  function isUnavailable(productId: string): boolean {
    return unavailable.value.has(productId)
  }

  async function refresh(): Promise<void> {
    // Un fallo de red no vacía la lista: dejarla en blanco haría aparecer
    // disponible todo lo que se acabó, que es peor que una lista vieja.
    ids.value = await apiFetch<string[]>('/catalog/unavailable')
  }

  function start(): void {
    void refresh().catch(() => {})

    if (polling.value !== null) return

    polling.value = setInterval(() => void refresh().catch(() => {}), POLL_MS)
  }

  function stop(): void {
    if (polling.value === null) return

    clearInterval(polling.value)
    polling.value = null
  }

  /** Se acabó. Marcar dos veces no es un error: se acabó igual. */
  async function mark(productId: string): Promise<void> {
    saving.value = true

    try {
      await apiFetch(`/catalog/products/${productId}/unavailable`, { method: 'POST' })
      await refresh()
    } finally {
      saving.value = false
    }
  }

  /** Volvió a haber. */
  async function restore(productId: string): Promise<void> {
    saving.value = true

    try {
      await apiFetch(`/catalog/products/${productId}/unavailable`, { method: 'DELETE' })
      await refresh()
    } finally {
      saving.value = false
    }
  }

  return { ids, unavailable, isUnavailable, refresh, start, stop, mark, restore, saving }
}
