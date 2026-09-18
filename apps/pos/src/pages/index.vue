<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { type SaleLine, useCart } from '../composables/useCart'
import { useCatalog, type CatalogProduct } from '../composables/useCatalog'
import { useShift, type ShiftSummary } from '../composables/useShift'
import { useOffline } from '../composables/useOffline'
import { useSync } from '../composables/useSync'
import { useKitchen } from '../composables/useKitchen'
import { useVocabulary } from '../composables/useVocabulary'
import { firstApiErrorMessage } from '../composables/httpClient'
import { STORES, get } from '../db/idb'
import { scoped } from '../db/scope'
import { amount } from '../utils/money'
import SaleSearch from '../components/SaleSearch.vue'
import SaleLines from '../components/SaleLines.vue'
import SaleTotals from '../components/SaleTotals.vue'
import PaymentPanel from '../components/PaymentPanel.vue'
import ShiftPanel from '../components/ShiftPanel.vue'
import ModifierPicker from '../components/ModifierPicker.vue'
import SaleSplitDialog from '../components/SaleSplitDialog.vue'
import { useDining } from '../composables/useDining'

/**
 * Pantalla de venta, perfil `scan_first` (A-04, §10 del plan).
 *
 * **Premisa: el cajero no toca el ratón.** El foco vive en la búsqueda y vuelve
 * ahí tras cualquier acción; los atajos cubren cobrar, suspender y retomar; el
 * total se lee desde un metro.
 *
 * Tres cosas que la pantalla sostiene y que vienen de decisiones, no de gusto:
 *
 *  - **Nada se vende sin turno abierto** (H4.1): si no hay turno, lo único que
 *    se puede hacer es abrirlo.
 *  - **El carrito sobrevive al cierre del navegador** (D-21): al montar se
 *    recupera la venta que quedó a medias.
 *  - **Las cuentas suspendidas son de la sucursal, no de la caja** (D-01,
 *    G-13): llegan por el sondeo y cualquier terminal puede retomarlas.
 */
const { t } = useI18n()
const cart = useCart()
const catalog = useCatalog()
const shift = useShift()
const sync = useSync()
const offline = useOffline()
const kitchen = useKitchen()
const toast = useToast()
/**
 * El vocabulario del local (A-04): en restaurante, suspender se llama abrir
 * cuenta. El modelo es el mismo —la cuenta abierta **es** la venta suspendida
 * (D-01)—; lo que cambia es cómo se nombra.
 */
const { vt, isRestaurant } = useVocabulary()
const dining = useDining()

/** Traspasar o dividir: el mismo gesto, distinto destino (F1-B). */
const moving = ref(false)

const search = ref<InstanceType<typeof SaleSearch> | null>(null)
const saleLines = ref<InstanceType<typeof SaleLines> | null>(null)
const payment = ref<InstanceType<typeof PaymentPanel> | null>(null)

const charging = ref(false)
const showSuspended = ref(false)
const showHelp = ref(false)
const suspendLabel = ref('')
const askingLabel = ref(false)
/** El plato que está esperando que le respondan sus modificadores (B-06). */
const asking = ref<CatalogProduct | null>(null)
const lastClosed = ref<string | null>(null)
const notice = ref('')

const canCharge = computed(() => (cart.lines.value.length ?? 0) > 0)

/**
 * Cuenta de salón: la misma venta, con mesa (D-01, F1-B).
 *
 * Lo único que cambia en la pantalla es que aparece el botón de comandar. No hay
 * una "pantalla de restaurante" aparte: sería un segundo carrito con las mismas
 * fórmulas y los mismos errores.
 */
const isTableTab = computed(() => Boolean(cart.sale.value?.dining_table_id))
const suspended = computed(() =>
  [...sync.suspendedSales.value.values()].sort((a, b) => (a.label ?? '').localeCompare(b.label ?? ''))
)

onMounted(async () => {
  await Promise.all([shift.refresh(), catalog.ready(), offline.restore()])

  // El catálogo se baja al abrir la caja, no en cada venta: bajarlo por ticket
  // tiraría la red abajo por nada (D-04).
  void catalog.sync()

  // Y con él, lo que hará falta si el servidor se cae: configuración, impuestos
  // y el bloque de correlativos. Lo que no se bajó antes del corte no se va a
  // poder bajar durante (H6).
  void offline.bootstrap()

  // Lo que quedó a medias: el criterio de aceptación de H2.5.
  const saved = await get<{ id: string }>(STORES.carts, scoped('current'))
  await cart.recover(saved?.id ?? null)

  sync.start()
  window.addEventListener('keydown', onKey)
  focusSearch()
})

/**
 * El modo degradado se enciende solo.
 *
 * Lo decide el sondeo, que es quien realmente sabe si el servidor de la
 * sucursal contesta: la red del navegador puede estar perfecta y el servidor
 * apagado.
 */
watch(
  () => sync.reachable.value,
  async (reachable) => {
    cart.degraded.value = !reachable && offline.canSellOffline.value

    if (reachable && offline.pending.value.length > 0) {
      // Volvió la red: lo pendiente sale solo, sin que nadie lo pida.
      await offline.flush()
    }
  }
)

onUnmounted(() => {
  window.removeEventListener('keydown', onKey)
})

function focusSearch() {
  search.value?.focus()
}

/**
 * La línea sobre la que actúan los atajos.
 *
 * Es siempre la **última agregada**, que es la que el cajero acaba de pasar y la
 * que va a querer corregir. Se mueve con las flechas y se elige tocándola.
 */
const activeLineId = ref<string | null>(null)

watch(
  () => cart.lines.value.map((line) => line.id).join(','),
  () => {
    const ids = cart.lines.value.map((line) => line.id)

    // Si la activa desapareció —se quitó la línea— manda la última; si acaba de
    // entrar una, también. En una caja lo nuevo es lo que importa.
    if (!activeLineId.value || !ids.includes(activeLineId.value)) {
      activeLineId.value = ids.at(-1) ?? null

      return
    }

    if (ids.length > 0 && ids.at(-1) !== activeLineId.value && cart.lines.value.length > 0) {
      activeLineId.value = ids.at(-1) ?? null
    }
  }
)

function moveActive(delta: number) {
  const ids = cart.lines.value.map((line) => line.id)

  if (ids.length === 0) return

  const current = ids.indexOf(activeLineId.value ?? '')
  const next = (current + delta + ids.length) % ids.length

  activeLineId.value = ids[next] ?? null
}

function editActiveQty() {
  const line = cart.lines.value.find((entry) => entry.id === activeLineId.value)

  if (line) saleLines.value?.startEdit(line)
}

async function setQty(lineId: string, qty: string) {
  activeLineId.value = lineId

  try {
    await cart.setQty(lineId, qty)
  } catch {
    notice.value = cart.lastError.value
  } finally {
    focusSearch()
  }
}

/**
 * Atajos de teclado (B-10).
 *
 * Se capturan en la ventana y no en el campo porque la mano del cajero no
 * siempre está ahí: tras cobrar, el foco puede estar en cualquier parte y F2
 * tiene que seguir funcionando.
 */
function onKey(event: KeyboardEvent) {
  // `+`, `−` y las flechas solo mandan **con el campo de búsqueda vacío**. Con
  // algo tecleado son caracteres y movimiento dentro de la lista, que es lo que
  // el cajero espera mientras escribe.
  const idle = search.value?.isEmpty() !== false

  if (idle && cart.lines.value.length > 0) {
    if (event.key === '+') {
      event.preventDefault()
      void cart.bumpQty(activeLineId.value ?? '', 1)

      return
    }

    if (event.key === '-') {
      event.preventDefault()
      void cart.bumpQty(activeLineId.value ?? '', -1)

      return
    }

    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
      event.preventDefault()
      moveActive(event.key === 'ArrowDown' ? 1 : -1)

      return
    }
  }

  if (event.key === 'F6' && cart.lines.value.length > 0) {
    // Teclear la cantidad exacta: el caso de "son doce", que sumar de a uno
    // convertiría en doce pulsaciones.
    event.preventDefault()
    editActiveQty()

    return
  }

  if (event.key === 'F1') {
    event.preventDefault()
    showHelp.value = !showHelp.value
  } else if (event.key === 'F2' && canCharge.value) {
    event.preventDefault()
    startCharging()
  } else if (event.key === 'F3' && canCharge.value) {
    event.preventDefault()
    askingLabel.value = true
  } else if (event.key === 'F4') {
    event.preventDefault()
    showSuspended.value = !showSuspended.value
  } else if (event.key === 'Escape') {
    if (charging.value || showSuspended.value || showHelp.value || askingLabel.value) {
      event.preventDefault()
      dismiss()
    }
  }
}

function dismiss() {
  charging.value = false
  showSuspended.value = false
  showHelp.value = false
  askingLabel.value = false
  focusSearch()
}

async function addProduct(product: CatalogProduct, qty = '1') {
  notice.value = ''

  // Un plato con modificadores se pregunta **antes** de agregarse: el servidor
  // rechaza el obligatorio sin responder, y descubrirlo después obligaría al
  // mesero a volver a la mesa a preguntar el término de la carne.
  if ((product.modifier_groups?.length ?? 0) > 0) {
    asking.value = product

    return
  }

  if (cart.degraded.value) {
    // Sin servidor la línea se arma acá y el total lo calcula el motor local.
    cart.addLocalLine(product, qty)
    focusSearch()

    return
  }

  try {
    await cart.addLine({ kind: 'product', product_id: product.id, qty })
  } catch {
    notice.value = cart.lastError.value
  } finally {
    // El foco vuelve solo: es lo que separa doce segundos de treinta.
    focusSearch()
  }
}

async function openMoving() {
  // Las mesas se releen al abrir el diálogo: el mapa puede haber cambiado
  // mientras el mesero atendía, y mover una cuenta a una mesa que se acaba de
  // ocupar es la forma más rápida de cobrarle dos veces al mismo grupo.
  await dining.refresh().catch(() => undefined)
  moving.value = true
}

async function transferTo(target: { tableId: string, lines: string[] }) {
  try {
    await cart.transfer({ tableId: target.tableId }, target.lines)
    moving.value = false
    toast.add({ title: t('transfer.moved'), color: 'success' })
  } catch {
    notice.value = cart.lastError.value
  }
}

async function splitInto(lineIds: string[]) {
  try {
    const target = await cart.split(lineIds)
    moving.value = false

    // La cuenta nueva queda suspendida en la misma mesa: se cobra por separado,
    // y el mapa sigue mostrando una sola mesa ocupada.
    toast.add({ title: t('transfer.splitDone', { label: target?.label ?? '' }), color: 'success' })
  } catch {
    notice.value = cart.lastError.value
  }
}

/** Agrega el plato con lo que el mesero respondió. */
async function addWithModifiers(answer: { modifiers: string[], notes: string }) {
  const product = asking.value

  if (!product) return

  asking.value = null
  notice.value = ''

  try {
    await cart.addLine({
      kind: 'product',
      product_id: product.id,
      qty: '1',
      modifiers: answer.modifiers,
      notes: answer.notes || undefined
    })
  } catch {
    notice.value = cart.lastError.value
  } finally {
    focusSearch()
  }
}

async function addScanned(code: string, qty = '1') {
  notice.value = ''

  if (cart.degraded.value) {
    const product = catalog.byBarcode(code)

    if (product) {
      cart.addLocalLine(product, qty)
    } else {
      // Sin servidor no hay a quién preguntarle por un código que la caja no
      // tiene cacheado. Decirlo es mejor que quedarse callado.
      notice.value = t('sale.notFound', { term: code })
    }

    focusSearch()

    return
  }

  try {
    await cart.addLine({ kind: 'scan', code, qty })
  } catch {
    notice.value = cart.lastError.value
  } finally {
    focusSearch()
  }
}

/**
 * Manda a cocina lo que la cuenta todavía no mandó.
 *
 * El servidor decide **qué** viaja —solo lo nuevo— y **a dónde** —cocina o
 * barra, según el producto—. La caja no elige destino: elegirlo a mano se
 * equivoca en hora pico.
 */
async function sendToKitchen() {
  const sale = cart.sale.value

  if (!sale) return

  notice.value = ''

  try {
    const tickets = await kitchen.send(sale.id)
    const numbers = tickets.map((ticket) => `#${ticket.number}`).join(' · ')

    toast.add({ title: t('dining.sent'), description: numbers, color: 'success' })
  } catch (err) {
    notice.value = firstApiErrorMessage(err)
  } finally {
    focusSearch()
  }
}

async function removeLine(lineId: string) {
  await cart.removeLine(lineId)
  focusSearch()
}

/**
 * Cambia el curso de una línea (G-16).
 *
 * Solo tiene efecto sobre lo que todavía no salió a cocina: una vez enviada, la
 * línea ya está en una comanda y moverla de curso no traería el plato de vuelta.
 * Esa regla la impone el servidor, no esta pantalla.
 */
async function setCourse(line: SaleLine, course: number) {
  await cart.updateLine(line.id, { course })
  focusSearch()
}

function startCharging() {
  charging.value = true
  void payment.value?.focusAmount()
}

async function doSuspend() {
  await cart.suspend(suspendLabel.value || undefined)
  suspendLabel.value = ''
  askingLabel.value = false
  notice.value = ''
  focusSearch()
}

async function resume(saleId: string) {
  await cart.resume(saleId)
  showSuspended.value = false
  focusSearch()
}

function onClosed(number: string | null) {
  charging.value = false
  lastClosed.value = number
  focusSearch()
}

function onShiftReady() {
  void shift.refresh()
  focusSearch()
}

function onShiftClosed(cut: ShiftSummary) {
  // El corte ya se mostró en el panel; acá solo se relee el estado para que la
  // pantalla vuelva a pedir apertura.
  void cut
  void shift.refresh()
}
</script>

<template>
  <!-- Sin turno no hay venta: es lo único que se puede hacer (H4.1). -->
  <ShiftPanel
    v-if="!shift.isOpen.value || shift.closing.value"
    @opened="onShiftReady"
    @closed="onShiftClosed"
    @cancel="shift.closing.value = false"
  />

  <div v-else class="grid h-full grid-cols-1 gap-3 p-3 lg:grid-cols-[1fr_22rem]">
    <!--
      Modo degradado: el cajero tiene que saberlo, y tiene que saber cuánto le
      queda. Un aviso que no dice cuántos números quedan no sirve para decidir
      si seguir vendiendo o llamar a alguien.
    -->
    <UAlert
      v-if="cart.degraded.value"
      color="warning"
      variant="subtle"
      icon="i-lucide-wifi-off"
      :title="t('offline.title')"
      :description="t('offline.remaining', {
        numbers: offline.remainingNumbers.value,
        pending: offline.pending.value.length
      })"
      class="lg:col-span-2"
    />

    <UAlert
      v-else-if="offline.pending.value.length > 0"
      color="info"
      variant="subtle"
      icon="i-lucide-upload-cloud"
      :title="t('offline.sending', { count: offline.pending.value.length })"
      class="lg:col-span-2"
    />
    <!-- Columna de trabajo: búsqueda arriba, líneas debajo. -->
    <section class="flex min-h-0 flex-col gap-3">
      <SaleSearch
        ref="search"
        :disabled="cart.busy.value"
        @pick="addProduct"
        @scan="addScanned"
      />

      <UAlert
        v-if="notice"
        color="error"
        variant="subtle"
        :description="notice"
        close
        @update:open="notice = ''"
      />

      <SaleLines
        ref="saleLines"
        class="min-h-0 grow"
        :lines="cart.lines.value"
        :busy="cart.busy.value"
        :courses="isRestaurant"
        :active-id="activeLineId"
        @remove="removeLine"
        @course="setCourse"
        @select="activeLineId = $event"
        @qty="setQty"
      />

      <!-- Los más vendidos de este cajero, a un clic o a una tecla (D-04). -->
      <div v-if="catalog.favourites.value.length > 0 && cart.lines.value.length === 0" class="flex flex-wrap gap-2">
        <UButton
          v-for="product in catalog.favourites.value"
          :key="product.id"
          color="neutral"
          variant="subtle"
          size="sm"
          @click="addProduct(product)"
        >
          {{ product.name }}
        </UButton>
      </div>
    </section>

    <!-- Columna fija: total siempre visible y acciones. -->
    <aside class="flex min-h-0 flex-col gap-3">
      <SaleTotals :sale="cart.sale.value" />

      <PaymentPanel v-if="charging" ref="payment" @closed="onClosed" />

      <template v-else>
        <UButton
          size="xl"
          block
          icon="i-lucide-banknote"
          :disabled="!canCharge"
          @click="startCharging"
        >
          {{ t('sale.charge') }}
          <UKbd value="F2" />
        </UButton>

        <!-- Cuenta de mesa: mandar a preparar es la acción que más se repite
             en un turno de salón, así que va a la vista y no en un menú. -->
        <UButton
          v-if="isTableTab"
          color="neutral"
          variant="ghost"
          icon="i-lucide-split"
          block
          :disabled="!canCharge"
          @click="openMoving"
        >
          {{ t('transfer.title') }}
        </UButton>

        <UButton
          v-if="isTableTab"
          color="neutral"
          variant="subtle"
          icon="i-lucide-chef-hat"
          block
          :loading="kitchen.saving.value"
          :disabled="!canCharge"
          @click="sendToKitchen"
        >
          {{ t('dining.sendToKitchen') }}
        </UButton>

        <div class="grid grid-cols-2 gap-2">
          <UButton
            color="neutral"
            variant="subtle"
            icon="i-lucide-pause"
            :disabled="!canCharge"
            @click="askingLabel = true"
          >
            {{ vt('sale.suspend') }}
          </UButton>
          <UButton
            color="neutral"
            variant="subtle"
            icon="i-lucide-list"
            @click="showSuspended = true"
          >
            {{ vt('sale.suspendedList') }}
            <UBadge v-if="suspended.length > 0" size="sm" variant="solid">
              {{ suspended.length }}
            </UBadge>
          </UButton>
        </div>
      </template>

      <!-- Tras cerrar: el comprobante y el recordatorio de la gaveta (Q-12). -->
      <UAlert
        v-if="lastClosed"
        color="success"
        variant="subtle"
        :title="t('sale.closed', { number: lastClosed })"
        :description="t('sale.openDrawerHint')"
        close
        @update:open="lastClosed = null"
      />

      <div class="grow" />

      <UButton
        color="neutral"
        variant="ghost"
        size="sm"
        icon="i-lucide-keyboard"
        @click="showHelp = true"
      >
        {{ t('shortcuts.title') }}
        <UKbd value="F1" />
      </UButton>
    </aside>
  </div>

  <!-- Suspender pide una referencia: "Mesa 4" o el nombre del cliente es lo que
       permite reconocerla después (D-01). -->
  <UModal
    :open="moving"
    :title="t('transfer.title')"
    @update:open="(value: boolean) => { if (!value) moving = false }"
  >
    <template #body>
      <SaleSplitDialog
        :lines="cart.lines.value"
        :tables="dining.tables.value"
        :busy="cart.busy.value"
        @transfer="transferTo"
        @split="splitInto"
        @cancel="moving = false"
      />
    </template>
  </UModal>

  <UModal
    :open="asking !== null"
    :title="asking?.name ?? ''"
    @update:open="(value: boolean) => { if (!value) { asking = null; focusSearch() } }"
  >
    <template #body>
      <ModifierPicker
        v-if="asking"
        :product="asking"
        @confirm="addWithModifiers"
        @cancel="asking = null; focusSearch()"
      />
    </template>
  </UModal>

  <UModal v-model:open="askingLabel" :title="vt('sale.suspend')">
    <template #body>
      <UFormField :label="vt('sale.label')" :description="vt('sale.labelHint')">
        <UInput v-model="suspendLabel" autofocus @keydown.enter.prevent="doSuspend" />
      </UFormField>
    </template>
    <template #footer>
      <UButton block @click="doSuspend">
        {{ vt('sale.suspend') }}
      </UButton>
    </template>
  </UModal>

  <UModal v-model:open="showSuspended" :title="vt('sale.suspendedList')">
    <template #body>
      <p v-if="suspended.length === 0" class="text-muted text-sm">
        {{ vt('sale.noSuspended') }}
      </p>
      <ul v-else class="divide-y divide-default">
        <li v-for="item in suspended" :key="item.id">
          <button
            type="button"
            class="flex w-full items-center gap-3 py-2 text-left"
            @click="resume(item.id)"
          >
            <span class="grow">{{ item.label || vt('sale.suspended') }}</span>
            <span class="pos-amount">{{ amount(item.total) }}</span>
            <UIcon name="i-lucide-corner-down-left" class="text-muted size-4" />
          </button>
        </li>
      </ul>
    </template>
  </UModal>

  <UModal v-model:open="showHelp" :title="t('shortcuts.title')">
    <template #body>
      <dl class="space-y-2 text-sm">
        <div class="flex items-center gap-3">
          <UKbd value="F2" /><dd>{{ t('shortcuts.charge') }}</dd>
        </div>
        <div class="flex items-center gap-3">
          <UKbd value="F3" /><dd>{{ vt('shortcuts.suspend') }}</dd>
        </div>
        <div class="flex items-center gap-3">
          <UKbd value="F4" /><dd>{{ vt('shortcuts.suspended') }}</dd>
        </div>
        <div class="flex items-center gap-3">
          <UKbd value="F6" /><dd>{{ t('shortcuts.qtyEdit') }}</dd>
        </div>
        <div class="flex items-center gap-3">
          <UKbd value="+" /><UKbd value="−" /><dd>{{ t('shortcuts.qtyBump') }}</dd>
        </div>
        <div class="flex items-center gap-3">
          <UKbd value="↑" /><UKbd value="↓" /><dd>{{ t('shortcuts.qtyMove') }}</dd>
        </div>
        <div class="flex items-center gap-3">
          <UKbd value="3*" /><dd>{{ t('shortcuts.multiply') }}</dd>
        </div>
        <div class="flex items-center gap-3">
          <UKbd value="Esc" /><dd>{{ t('shortcuts.clear') }}</dd>
        </div>
      </dl>
      <p class="text-muted mt-3 text-sm">
        {{ t('shortcuts.hint') }}
      </p>
    </template>
  </UModal>
</template>
