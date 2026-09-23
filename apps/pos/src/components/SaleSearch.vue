<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCatalog, type CatalogProduct } from '../composables/useCatalog'
import { amount } from '../utils/money'

/**
 * El campo de búsqueda del perfil `scan_first`.
 *
 * **Premisa: el cajero no toca el ratón.** El foco vuelve solo tras cualquier
 * acción, el lector escribe acá y confirma con Enter, y cada lectura produce
 * exactamente una línea.
 *
 * La diferencia entre doce y treinta segundos por transacción está casi toda
 * acá: un campo que pierde el foco obliga a levantar la mano del teclado, y eso
 * se paga en cada ticket del día.
 */
const props = defineProps<{ disabled?: boolean }>()
const emit = defineEmits<{
  pick: [CatalogProduct, string],
  scan: [string, string],
  /** El campo quedó vacío o dejó de estarlo: la pantalla lo usa para sus atajos. */
  empty: [boolean]
}>()

const { t } = useI18n()
const catalog = useCatalog()

const term = ref('')
/**
 * Contenedor, no el campo.
 *
 * Un `ref` sobre `UInput` devuelve la instancia del componente, no el elemento:
 * llamarle `focus()` falla. Se busca el `input` real dentro del contenedor, que
 * funciona con cualquier versión de la biblioteca y no depende de que exponga un
 * método.
 */
const root = ref<HTMLElement | null>(null)
const highlighted = ref(0)

/**
 * Multiplicador de cantidad: `3*` antes del producto (B-10).
 *
 * **El lector es un teclado.** Un código de barras entra como trece dígitos
 * seguidos de Enter, así que interpretar dígitos sueltos como cantidad
 * convertiría cada lectura en una cantidad de siete mil quinientos. El `*` no
 * lo escribe ningún lector, y por eso es lo que separa "tres de esto" de "esto".
 *
 * Funciona en las dos formas en que se teclea de verdad: `3*` y después pasar el
 * lector, o `3*queso` de un tirón.
 */
const MULTIPLIER = /^(\d+(?:[.,]\d+)?)\s*[*xX]\s*(.*)$/

/** Cantidad armada esperando producto. Se muestra como ×3 junto al campo. */
const pendingQty = ref('')

/** Mientras se espera al catálogo: el campo se bloquea y lo dice. */
const waiting = ref(false)

/** Lo que se busca de verdad: el término sin el multiplicador. */
const query = computed(() => {
  const match = MULTIPLIER.exec(term.value)

  return match ? match[2]!.trim() : term.value
})

const results = computed(() => catalog.search(query.value, 8))

/**
 * El catálogo todavía está en camino.
 *
 * La caja arranca con la caché vacía y lo baja en segundo plano. Hasta que
 * llega, la búsqueda local no encuentra nada — y decir "no existe" en ese rato
 * es mentir: el producto está, solo que todavía no acá. En una caja esa mentira
 * termina con el cajero diciéndole al cliente que no hay algo que sí hay.
 */
const warming = computed(() => catalog.count.value === 0)

/**
 * La cantidad que le toca a la línea que entre ahora.
 *
 * Primero la que se está tecleando, después la que quedó armada; si no hay
 * ninguna, una. Se limpia sola al usarse: dejarla pegada haría que el siguiente
 * producto entrara con la cantidad del anterior, y eso se descubre al cobrar.
 */
function takeQty(): string {
  const match = MULTIPLIER.exec(term.value)
  const typed = match ? match[1]!.replace(',', '.') : ''
  const qty = typed || pendingQty.value || '1'

  pendingQty.value = ''

  return qty
}

watch(term, (value) => {
  emit('empty', value.length === 0)
})

/**
 * La lista solo aparece **mientras se escribe**.
 *
 * Con el campo vacío, `search('')` devuelve los más usados por este cajero
 * (D-04), y como el foco vuelve solo al campo tras cada acción, la lista quedaba
 * abierta de forma permanente tapando las líneas del carrito. Los favoritos
 * tienen su propia fila en la pantalla: acá estorbaban justo lo que el cajero
 * necesita mirar.
 */
const showing = computed(() => term.value.length > 0)

watch(results, () => {
  highlighted.value = 0
})

/**
 * El lector termina con Enter.
 *
 * Si lo tecleado coincide **exacto** con un código de barras, se agrega sin
 * mostrar la lista: mostrar resultados y esperar una confirmación convertiría
 * una lectura en dos pulsaciones.
 */
function submit() {
  const raw = query.value.trim()
  const match = MULTIPLIER.exec(term.value)

  // `3*` a secas arma la cantidad y espera: es la forma natural de teclearlo
  // cuando después viene el lector. Se limpia el campo pero no la cantidad.
  if (match && raw === '') {
    pendingQty.value = match[1]!.replace(',', '.')
    term.value = ''
    focus()

    return
  }

  if (raw === '') return

  const qty = takeQty()

  void resolve(raw, qty)
}

/**
 * Resuelve lo tecleado, esperando al catálogo si hace falta.
 *
 * Sin catálogo local no hay con qué comparar, así que antes de dar nada por
 * inexistente se espera la bajada en curso. Con la caché ya llena esto no cuesta
 * nada: devuelve de inmediato.
 */
async function resolve(raw: string, qty: string) {
  if (warming.value) {
    waiting.value = true

    try {
      await catalog.whenReady()
    } finally {
      waiting.value = false
    }
  }

  const scanned = catalog.byBarcode(raw)

  if (scanned) {
    emit('scan', raw, qty)
    reset()

    return
  }

  // Se vuelve a mirar la lista: si el catálogo acaba de llegar, la coincidencia
  // que faltaba ya está.
  const chosen = results.value[highlighted.value] ?? catalog.search(raw, 1)[0]

  if (chosen) {
    emit('pick', chosen, qty)
    reset()

    return
  }

  // Sin coincidencia local queda una posibilidad: un producto creado en otra
  // terminal después de la última sincronización del catálogo. Se le pregunta al
  // servidor **al confirmar**, que no es lo que D-04 prohíbe —eso es una
  // consulta por tecla— y es la diferencia entre vender y decirle al cliente que
  // el producto no existe. Sin servidor, la pantalla ya avisa que no lo tiene.
  emit('scan', raw, qty)
  reset()
}

function choose(product: CatalogProduct) {
  emit('pick', product, takeQty())
  reset()
}

function move(delta: number) {
  if (results.value.length === 0) return

  highlighted.value =
    (highlighted.value + delta + results.value.length) % results.value.length
}

function reset() {
  term.value = ''
  highlighted.value = 0
  // Escape también desarma la cantidad: es el gesto de "no, así no".
  pendingQty.value = ''
  focus()
}

/** Vuelve el foco acá. Lo llama la pantalla tras cualquier acción. */
function focus() {
  void nextTick(() => {
    root.value?.querySelector('input')?.focus()
  })
}

defineExpose({ focus, reset, isEmpty: () => term.value.length === 0 })
</script>

<template>
  <div ref="root" class="relative">
    <UInput
      v-model="term"
      :placeholder="t('sale.search')"
      icon="i-lucide-scan-barcode"
      size="xl"
      autofocus
      autocomplete="off"
      :disabled="props.disabled"
      :loading="waiting"
      class="w-full"
      @keydown.enter.prevent="submit"
      @keydown.down.prevent="move(1)"
      @keydown.up.prevent="move(-1)"
      @keydown.esc.prevent="reset"
    />

    <!-- La cantidad armada, a la vista. Una cantidad invisible es la forma más
         rápida de cobrar tres de algo que el cliente lleva uno. -->
    <UBadge
      v-if="pendingQty"
      color="primary"
      variant="solid"
      size="lg"
      class="absolute right-3 top-1/2 -translate-y-1/2"
    >
      ×{{ pendingQty }}
    </UBadge>

    <!--
      Los resultados aparecen sobre el carrito en vez de empujarlo: si la lista
      moviera las líneas, el cajero perdería de vista lo que lleva agregado.
    -->
    <div
      v-if="showing && results.length > 0"
      class="absolute inset-x-0 top-full z-20 mt-1 overflow-hidden rounded-lg border border-default bg-default shadow-lg"
    >
      <button
        v-for="(product, index) in results"
        :key="product.id"
        type="button"
        class="flex w-full items-center gap-3 px-3 py-2 text-left"
        :class="index === highlighted ? 'bg-elevated' : ''"
        @click="choose(product)"
        @mouseenter="highlighted = index"
      >
        <span class="grow truncate">{{ product.name }}</span>
        <span class="text-muted shrink-0 text-xs">{{ product.sku }}</span>
        <span class="pos-amount shrink-0 font-medium">{{ amount(product.price) }}</span>
      </button>
    </div>

    <!-- Cargando no es lo mismo que no existe, y la pantalla lo distingue. -->
    <p
      v-else-if="warming && term.length > 0"
      class="absolute inset-x-0 top-full z-20 mt-1 rounded-lg border border-default bg-default px-3 py-2 text-sm text-muted shadow-lg"
    >
      {{ t('sale.catalogWarming') }}
    </p>

    <p
      v-else-if="term.length > 1"
      class="absolute inset-x-0 top-full z-20 mt-1 rounded-lg border border-default bg-default px-3 py-2 text-sm text-muted shadow-lg"
    >
      {{ t('sale.notFound', { term }) }}
    </p>
  </div>
</template>
