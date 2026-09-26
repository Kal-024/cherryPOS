<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { apiOpen, firstApiErrorMessage } from '../composables/httpClient'
import { type DiningTable, useDining } from '../composables/useDining'
import { useCart } from '../composables/useCart'
import { useKitchen } from '../composables/useKitchen'
import { useOperator } from '../composables/useOperator'
import { amount } from '../utils/money'

/**
 * Mapa del salón (F1-B, §10).
 *
 * **Las mesas van donde están de verdad.** El plan pide el mapa según la
 * disposición real del local: una lista de botones obligaría al mesero a
 * traducir "Mesa 7" a un lugar físico cada vez, y en hora pico eso es un plato
 * en la mesa equivocada.
 *
 * El color dice el estado y el número dice el tiempo: una mesa de hace dos horas
 * necesita atención distinta que una de cinco minutos, y esa es la lectura que
 * un mesero hace de un vistazo desde la puerta de la cocina.
 *
 * Tocar una mesa abre su cuenta —que es una venta suspendida (D-01)— y lleva a
 * la pantalla de venta. No hay dos modelos de cuenta: hay uno.
 */
definePage({ meta: { permission: 'dining.read' } })

const { t } = useI18n()
const router = useRouter()
const dining = useDining()
const cart = useCart()
const kitchen = useKitchen()
const { can } = useOperator()
const toast = useToast()

const selectedArea = ref<string | null>(null)
const opening = ref<DiningTable | null>(null)
const guests = ref(2)
const merging = ref<DiningTable | null>(null)
const mergeWith = ref<string[]>([])

/**
 * La libreta del mesero (F1-B, §10).
 *
 * «Cumpleaños», «apurados, tienen función a las 8», «paga el de la camisa
 * azul». No es la nota de la línea —esa va a la comanda y es de la cocina—: es
 * del servicio, y se escribe en una tablet mientras el cliente habla. Un campo
 * grande y dos botones: cualquier cosa más elaborada no se usa con prisa.
 */
const noting = ref<DiningTable | null>(null)
const noteDraft = ref('')

/**
 * Unir arrastrando una mesa sobre otra.
 *
 * El camino anterior no se entendía, y con razón: el botón "Unir mesas" elegía
 * la principal **por su cuenta** —la primera libre del área— y solo dejaba
 * marcar cuáles se le sumaban. Nadie decía cuál mandaba ni por qué.
 *
 * Juntar dos mesas es un gesto físico: en el local alguien las empuja hasta que
 * se tocan. Acá es el mismo gesto. La mesa sobre la que se suelta es la
 * principal —queda en su sitio, que es lo que el mesero espera— y la arrastrada
 * se le acopla.
 *
 * El modal sigue existiendo para pantallas chicas y para mesas que están lejos
 * en el plano, pero ahora deja **elegir la principal** en vez de decidirla en
 * silencio.
 */
const dragged = ref<DiningTable | null>(null)
const dropTarget = ref<DiningTable | null>(null)

/**
 * Soltar el dedo también emite un `click`.
 *
 * Sin esto, arrastrar una mesa sobre otra las unía **y además** abría la cuenta
 * de la mesa soltada: el gesto hacía dos cosas y solo una se había pedido. Se
 * recuerda el punto donde empezó el arrastre y se descarta el toque si el dedo
 * recorrió más que un temblor de mano.
 */
const DRAG_SLOP = 8

let dragStart: { x: number, y: number } | null = null
let justDragged = false

const canServe = computed(() => can('dining.serve'))

const visible = computed(() =>
  dining.tables.value.filter((table) => !selectedArea.value || table.area_id === selectedArea.value)
)

/**
 * Dónde se dibuja cada mesa.
 *
 * Las unidas se pintan **pegadas a su principal**, en fila. Unir dos mesas que
 * siguen cada una en su rincón del plano no se lee como una sola unidad, que es
 * justo lo que son: una cuenta, un cobro. Al arrimarlas, el grupo se mueve y se
 * entiende de un vistazo desde el otro lado del salón.
 *
 * **No se tocan sus coordenadas.** `pos_x` y `pos_y` dicen dónde está el mueble
 * y eso no cambia porque dos mesas compartan cuenta; además moverlas de verdad
 * exigiría `dining.manage`, que un mesero no tiene. Al separarlas, cada una
 * vuelve sola a su sitio.
 */
/**
 * Hasta dónde llega el plano, más un margen para que la última mesa respire.
 *
 * Se mide con el tamaño **de cada mesa**: desde que cada una tiene el suyo, una
 * constante única recortaba el lienzo justo donde estaba la mesa larga del fondo.
 */
const planSize = computed(() => {
  const right = Math.max(0, ...placed.value.map((item) => item.x + item.table.width))
  const bottom = Math.max(0, ...placed.value.map((item) => item.y + item.table.height))

  return {
    minWidth: `${Math.max(512, right + 40)}px`,
    minHeight: `${Math.max(512, bottom + 40)}px`
  }
})

const placed = computed(() => {
  const order = new Map<string, number>()

  return visible.value.map((table) => {
    if (!table.merged_into_id) return { table, x: table.pos_x, y: table.pos_y }

    const main = dining.tables.value.find((other) => other.id === table.merged_into_id)

    if (!main) return { table, x: table.pos_x, y: table.pos_y }

    // Se acumula el ancho de las que ya se acoplaron, no un múltiplo fijo: con
    // mesas de tamaños distintos, un paso constante las superpone o deja huecos.
    const offset = order.get(main.id) ?? 0
    order.set(main.id, offset + table.width - 4)

    // Menos cuatro píxeles: los bordes se tocan en vez de dejar una ranura.
    return { table, x: main.pos_x + main.width - 4 + offset, y: main.pos_y }
  })
})

/** ¿Esta mesa manda sobre otras? Entonces se puede deshacer su grupo. */
function hasMerged(table: DiningTable): boolean {
  return dining.tables.value.some((other) => other.merged_into_id === table.id)
}

/**
 * El menú de la mesa (clic derecho, o dedo apoyado en la tablet).
 *
 * Antes la única forma de separar era un botón al pie del salón, uno por grupo,
 * que deshacía **el grupo entero**: con tres mesas empujadas contra una, devolver
 * una sola a su sitio obligaba a separar las cuatro y rearmar a mano lo que nadie
 * quiso deshacer. Las acciones viven donde está la mesa, que es donde el mesero
 * las busca.
 */
function menuFor(table: DiningTable) {
  const items = []

  if (table.state !== 'merged' && (table.sale || canServe.value)) {
    items.push({
      label: table.sale ? t('dining.viewAccount') : t('dining.openTab'),
      icon: table.sale ? 'i-lucide-receipt' : 'i-lucide-plus',
      onSelect: () => { void tap(table) }
    })
  }

  if (!canServe.value) return items

  if (table.sale) {
    // La precuenta se pide caminando el salón, no desde la caja: el cliente
    // levanta la mano y el mesero ya está al lado de la mesa.
    items.push({
      label: t('dining.printBill'),
      icon: 'i-lucide-printer',
      onSelect: () => { void printBill(table) }
    })

    items.push({
      label: t('dining.tableNote'),
      icon: 'i-lucide-sticky-note',
      onSelect: () => openNote(table)
    })
  }

  // Soltar solo esta: el resto del grupo sigue junto.
  if (table.merged_into_id !== null) {
    items.push({
      label: t('dining.splitThis'),
      icon: 'i-lucide-unlink',
      onSelect: () => { void split(table) }
    })
  }

  if (hasMerged(table)) {
    items.push({
      label: t('dining.splitGroup'),
      icon: 'i-lucide-scissors',
      onSelect: () => { void split(table) }
    })
  }

  if (table.state === 'free') {
    items.push({
      label: t('dining.mergeWith'),
      icon: 'i-lucide-link',
      onSelect: () => pickMain(table.id)
    })
  }

  return items
}

/** Todas las libres del área: cualquiera puede ser la principal. */
const freeTables = computed(() =>
  visible.value
    .filter((table) => table.state === 'free')
    .map((table) => ({ label: table.name ?? table.code, value: table.id }))
)

/** Cambiar de principal descarta lo marcado: la lista de candidatas es otra. */
function pickMain(id: string) {
  merging.value = visible.value.find((table) => table.id === id) ?? null
  mergeWith.value = []
}

/** Las libres de la misma zona: las únicas que se pueden unir a esta. */
const mergeCandidates = computed(() =>
  visible.value
    .filter((table) => table.state === 'free' && table.id !== merging.value?.id)
    .map((table) => ({ label: table.name ?? table.code, value: table.id }))
)

/** Mesas con un curso pedido y todavía sin marchar. */
const withHeld = computed(() =>
  visible.value.filter((table) => (table.sale?.kitchen?.held_courses.length ?? 0) > 0)
)

onMounted(() => dining.start())
onUnmounted(() => dining.stop())

function tone(table: DiningTable): string {
  if (table.state === 'merged') return 'border-dashed border-default text-muted'
  if (table.state !== 'occupied') return 'border-default hover:border-primary'

  // Cuenta pedida gana sobre todo: son los únicos que están esperando **al
  // mesero** y no a la cocina. Cada minuto ahí es una mesa que no se libera con
  // gente en la puerta.
  if (table.sale?.bill_requested_at) return 'border-info bg-info/10'

  // Comida lista gana sobre el tiempo sentado: una mesa de hace dos horas puede
  // esperar, un plato en el pase se enfría.
  if (table.sale?.kitchen?.state === 'ready') return 'border-success bg-success/10'

  const minutes = dining.elapsedMinutes(table) ?? 0

  // Más de una hora sentados: no es una alarma, es una mesa que probablemente
  // ya quiere la cuenta.
  return minutes >= 60
    ? 'border-error bg-error/10'
    : 'border-warning bg-warning/10'
}

function kitchenLabel(state: 'cooking' | 'ready' | 'held'): string {
  return t(`kitchen.state${state.charAt(0).toUpperCase()}${state.slice(1)}`)
}

/**
 * El estado del pedido, como distintivo en la esquina (F1-B, G-16).
 *
 * Antes era **un renglón más** dentro de la ficha, debajo del importe y los
 * minutos: con "Listo para servir" el texto se salía del cuadro. Y un renglón de
 * texto no es lo que el mesero lee de un vistazo desde la puerta de la cocina —
 * lee **color y posición**, como el punto de los mensajes sin leer.
 *
 * Una mesa libre no muestra nada: el distintivo significa "algo pasa acá".
 */
function kitchenBadge(state: 'cooking' | 'ready' | 'held'): string {
  return t(`kitchen.badge${state.charAt(0).toUpperCase()}${state.slice(1)}`)
}

function kitchenTone(state: 'cooking' | 'ready' | 'held'): 'success' | 'warning' | 'neutral' {
  // Lo listo grita, lo que se cocina avisa, lo retenido solo recuerda.
  if (state === 'ready') return 'success'

  return state === 'cooking' ? 'warning' : 'neutral'
}

/**
 * Marcha el curso que sigue de esta mesa (G-16).
 *
 * Se hace desde el mapa y no desde la cuenta porque el momento de marchar se
 * decide **mirando la mesa**: el mesero pasa, ve que terminaron el fuerte y
 * suelta el postre sin abrir nada.
 */
async function fire(table: DiningTable) {
  if (!table.sale) return

  try {
    await kitchen.fire(table.sale.id)
    toast.add({ title: t('kitchen.fired'), color: 'success' })
    await dining.refresh()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function tap(table: DiningTable) {
  // El toque que cierra un arrastre no es un toque.
  if (justDragged) {
    justDragged = false

    return
  }

  if (table.state === 'merged') {
    toast.add({ title: t('dining.tableMerged'), color: 'info' })

    return
  }

  if (table.sale) {
    await openAccount(table.sale.id)

    return
  }

  if (!canServe.value) return

  opening.value = table
  guests.value = Math.min(table.seats, 2)
}

async function confirmOpen() {
  if (!opening.value) return

  try {
    const saleId = await dining.openTable(opening.value.id, guests.value)
    opening.value = null
    await openAccount(saleId)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

/**
 * Retoma la cuenta y va a la caja.
 *
 * La cuenta se retoma **en esta terminal**: el mesero puede haberla abierto en
 * otra y cobrarla acá, que es justo lo que el carrito en sesión hacía imposible.
 */
async function openAccount(saleId: string) {
  try {
    await cart.resume(saleId)
    await router.push('/')
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

/** Qué mesa hay debajo del punto donde se soltó. */
function tableUnder(event: PointerEvent, exclude: string): DiningTable | null {
  const under = document.elementFromPoint(event.clientX, event.clientY)
  const id = under?.closest('[data-table-id]')?.getAttribute('data-table-id')

  if (!id || id === exclude) return null

  return visible.value.find((table) => table.id === id) ?? null
}

function startTableDrag(event: PointerEvent, table: DiningTable) {
  if (!canServe.value || table.state === 'merged') return

  dragged.value = table
  dragStart = { x: event.clientX, y: event.clientY }
  justDragged = false
  ;(event.currentTarget as HTMLElement)?.setPointerCapture?.(event.pointerId)
}

function onTableDrag(event: PointerEvent) {
  if (!dragged.value || !dragStart) return

  const far =
    Math.abs(event.clientX - dragStart.x) > DRAG_SLOP ||
    Math.abs(event.clientY - dragStart.y) > DRAG_SLOP

  if (!far) return

  justDragged = true
  dropTarget.value = tableUnder(event, dragged.value.id)
}

async function endTableDrag(event: PointerEvent) {
  const source = dragged.value
  const moved = justDragged
  const target = dropTarget.value ?? (source ? tableUnder(event, source.id) : null)

  dragged.value = null
  dropTarget.value = null
  dragStart = null

  // Sin recorrido fue un toque, y un toque abre la cuenta: lo resuelve `tap`.
  if (!source || !target || !moved) {
    justDragged = false

    return
  }

  try {
    // El servidor impone el resto: no une una mesa ya unida ni absorbe una con
    // cuenta abierta. Acá no se repiten esas reglas, se dejan hablar.
    await dining.merge(target.id, [source.id])
    toast.add({ title: t('floorPlan.merged'), color: 'success' })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function confirmMerge() {
  if (!merging.value || mergeWith.value.length === 0) return

  try {
    await dining.merge(merging.value.id, mergeWith.value)
    merging.value = null
    mergeWith.value = []
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

/**
 * Imprime la cuenta para el cliente (F1-B).
 *
 * Se abre en una pestaña, como el comprobante: lo que el mesero necesita es el
 * visor del navegador con su botón de imprimir.
 *
 * **Imprimirla marca la mesa como "cuenta pedida"** — lo hace el servidor, al
 * generar el papel. Un botón aparte para marcarlo sería un segundo paso que
 * alguien olvida, y entonces el mapa mentiría con gente esperando mesa.
 */
async function printBill(table: DiningTable) {
  if (!table.sale) return

  try {
    await apiOpen(`/sales/${table.sale.id}/pre-bill/pdf`)
    await dining.refresh()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

function openNote(table: DiningTable) {
  noting.value = table
  noteDraft.value = table.sale?.notes ?? ''
}

async function saveNote() {
  if (!noting.value) return

  try {
    // Vaciar el campo borra la nota: un segundo botón para eso sería un botón
    // más que buscar con el cliente esperando.
    await dining.setNote(noting.value.id, noteDraft.value)
    noting.value = null
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function split(table: DiningTable) {
  try {
    await dining.split(table.id)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex h-full flex-col gap-3 p-3">
    <div class="flex flex-wrap items-center gap-2">
      <UButton
        :color="selectedArea === null ? 'primary' : 'neutral'"
        :variant="selectedArea === null ? 'solid' : 'subtle'"
        size="sm"
        :label="t('dining.allAreas')"
        @click="selectedArea = null"
      />
      <UButton
        v-for="area in dining.areas.value"
        :key="area.id"
        :color="selectedArea === area.id ? 'primary' : 'neutral'"
        :variant="selectedArea === area.id ? 'solid' : 'subtle'"
        size="sm"
        :label="area.name"
        @click="selectedArea = area.id"
      />

      <div class="grow" />

      <UBadge color="neutral" variant="subtle">
        {{ t('dining.occupiedCount', { count: dining.occupied.value.length, total: dining.tables.value.length }) }}
      </UBadge>
    </div>

    <!--
      Lo que espera a que alguien lo marche, arriba y a la vista.

      Va acá y no dentro de la mesa por dos razones: un botón dentro de otro
      botón no es HTML válido, y sobre todo porque esto es una **lista de
      pendientes del turno** — el mesero necesita ver de un vistazo cuántos
      cursos quedan sin soltar, no descubrirlo mesa por mesa.
    -->
    <div v-if="withHeld.length" class="flex flex-wrap items-center gap-2">
      <span class="text-muted text-sm">{{ t('kitchen.stateHeld') }}:</span>
      <UButton
        v-for="table in withHeld"
        :key="table.id"
        icon="i-lucide-flame"
        color="warning"
        variant="subtle"
        size="sm"
        :disabled="!canServe"
        :loading="kitchen.saving.value"
        @click="fire(table)"
      >
        {{ table.name ?? table.code }} · {{ t('kitchen.fire') }}
      </UButton>
    </div>

    <div
      v-if="dining.tables.value.length === 0"
      class="text-muted flex grow flex-col items-center justify-center gap-1 text-center"
    >
      <UIcon name="i-lucide-layout-grid" class="size-8" />
      <p class="font-medium">
        {{ t('dining.noTables') }}
      </p>
      <p class="text-sm">
        {{ t('dining.noTablesHint') }}
      </p>
    </div>

    <!-- El plano. Las coordenadas vienen de la configuración del salón: cada
         mesa está donde está en el local.

         **Lo que se anima es el toque, no el dato.** La transición estaba sobre
         todas las propiedades y en la clase estática de todas las fichas, y el
         mapa se reemplaza entero cada cinco segundos por el sondeo: las N mesas
         recalculaban color a la vez y las N transiciones corrían juntas, de modo
         que tocar una parecía estar modificándolas todas. El color de estado
         ahora cambia de golpe, como un semáforo, y lo único que se mueve es la
         ficha que el dedo está apretando. -->
    <div v-else class="min-h-0 grow overflow-auto rounded-lg border border-default bg-elevated/30 p-4">
      <!-- El lienzo crece con el plano. Con un tamaño fijo, una mesa colocada
           lejos quedaba recortada y **el mesero no podía tocarla**: el salón se
           dibuja según el local, y el local no cabe siempre en 32 rem. -->
      <div class="relative" :style="planSize">
        <!-- El menú no agrega nodo propio: la mesa sigue siendo el botón de
             siempre, con su sitio en el plano. En la tablet se abre dejando el
             dedo apoyado, que es el gesto que ya conocen. -->
        <UContextMenu
          v-for="{ table, x, y } in placed"
          :key="table.id"
          :items="menuFor(table)"
          :disabled="menuFor(table).length === 0"
          :press-open-delay="500"
        >
          <!-- El tamaño es del plano y se configura en la trastienda: acá se
               respeta. El mesero une y separa mesas, no mueve los muebles. -->
          <button
            type="button"
            :data-table-id="table.id"
            class="absolute flex touch-none flex-col items-center justify-center gap-0.5 border-2 p-2 text-sm transition-transform duration-100 active:scale-95"
            :class="[
              tone(table),
              table.shape === 'round' ? 'rounded-full' : 'rounded-lg',
              dragged?.id === table.id ? 'opacity-60' : '',
              dropTarget?.id === table.id ? 'border-primary ring-2 ring-primary' : ''
            ]"
            :style="{
              left: `${x}px`,
              top: `${y}px`,
              width: `${table.width}px`,
              height: `${table.height}px`
            }"
            @click="tap(table)"
            @pointerdown="startTableDrag($event, table)"
            @pointermove="onTableDrag"
            @pointerup="endTableDrag"
            @pointercancel="endTableDrag"
          >
            <span class="font-semibold">{{ table.name ?? table.code }}</span>

            <!-- Una nota que nadie ve no sirve de nada: la ficha la marca y el
                 mesero sabe que ahí hay algo escrito sin abrir nada. A la
                 izquierda, porque la derecha es del estado del pedido. -->
            <UIcon
              v-if="table.sale?.notes"
              name="i-lucide-sticky-note"
              class="absolute top-1 left-1 size-4"
            />

            <!-- El estado del pedido, como el punto de los mensajes sin leer: se
                 lee por color y posición, no leyendo (G-16). -->
            <UBadge
              v-if="table.sale?.bill_requested_at"
              color="info"
              variant="solid"
              size="sm"
              class="absolute -top-2 -right-2 shadow"
              :title="t('dining.waitingToPay', { count: dining.waitingToPayMinutes(table) ?? 0 })"
            >
              {{ t('dining.billBadge', { count: dining.waitingToPayMinutes(table) ?? 0 }) }}
            </UBadge>

            <UBadge
              v-else-if="table.sale?.kitchen"
              :color="kitchenTone(table.sale.kitchen.state)"
              variant="solid"
              size="sm"
              class="absolute -top-2 -right-2 shadow"
              :title="kitchenLabel(table.sale.kitchen.state)"
            >
              {{ kitchenBadge(table.sale.kitchen.state) }}
            </UBadge>

            <template v-if="table.sale">
              <span class="pos-amount text-xs">{{ amount(table.sale.total) }}</span>
              <span class="text-xs">
                {{ t('dining.minutes', { count: dining.elapsedMinutes(table) ?? 0 }) }}
              </span>
              <span v-if="table.sale.guests" class="text-muted text-xs">
                {{ t('dining.guests', { count: table.sale.guests }) }}
              </span>
            </template>

            <span v-else-if="table.state === 'merged'" class="text-xs">
              {{ t('dining.merged') }}
            </span>

            <span v-else class="text-muted text-xs">
              {{ t('dining.seats', { count: table.seats }) }}
            </span>
          </button>
        </UContextMenu>
      </div>
    </div>

    <div v-if="canServe" class="flex flex-wrap items-center gap-2">
      <!-- Arrastrar una mesa sobre otra es el camino corto; este botón es el
           largo, para mesas que están lejos en el plano. -->
      <UButton
        icon="i-lucide-link"
        color="neutral"
        variant="subtle"
        size="sm"
        :disabled="visible.filter((table) => table.state === 'free').length < 2"
        :label="t('dining.merge')"
        @click="merging = visible.find((table) => table.state === 'free') ?? null"
      />

      <span class="text-muted text-xs">{{ t('floorPlan.mergeDrop') }}</span>

      <!-- Separar ya no vive acá: era un botón por grupo que deshacía el grupo
           entero, y la mesa que hay que soltar se señala en el plano. -->
      <span class="text-muted text-xs">{{ t('dining.menuHint') }}</span>
    </div>

    <UModal
      :open="opening !== null"
      :title="t('dining.openTable', { code: opening?.name ?? opening?.code ?? '' })"
      @update:open="(value: boolean) => { if (!value) opening = null }"
    >
      <template #body>
        <div class="flex flex-col gap-3">
          <UFormField :label="t('dining.guestsLabel')" :help="t('dining.guestsHint')">
            <UInput
              v-model.number="guests"
              type="number"
              min="1"
              max="99"
              class="w-28"
            />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="opening = null"
            />
            <UButton
              :loading="dining.saving.value"
              :label="t('dining.openTab')"
              @click="confirmOpen"
            />
          </div>
        </div>
      </template>
    </UModal>

    <UModal
      :open="noting !== null"
      :title="t('dining.tableNote')"
      @update:open="(value: boolean) => { if (!value) noting = null }"
    >
      <template #body>
        <div class="flex flex-col gap-3">
          <p class="text-muted text-sm">
            {{ t('dining.noteHint') }}
          </p>

          <!-- Grande y sin rótulo: se escribe de pie, con una mano, mirando la
               mesa. El teclado de la tablet ya tapa media pantalla. -->
          <UTextarea
            v-model="noteDraft"
            :rows="5"
            autofocus
            :placeholder="t('dining.notePlaceholder')"
            class="w-full text-base"
          />

          <div class="flex justify-end gap-2">
            <UButton
              size="lg"
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="noting = null"
            />
            <UButton
              size="lg"
              :loading="dining.saving.value"
              :label="t('dining.noteSave')"
              @click="saveNote"
            />
          </div>
        </div>
      </template>
    </UModal>

    <UModal
      :open="merging !== null"
      :title="t('dining.merge')"
      @update:open="(value: boolean) => { if (!value) { merging = null; mergeWith = [] } }"
    >
      <template #body>
        <div class="flex flex-col gap-3">
          <p class="text-muted text-sm">
            {{ t('dining.mergeHint') }}
          </p>

          <!-- Cuál manda se elige, no se adivina: la principal conserva su
               sitio en el plano y es la que cobra por todas. -->
          <UFormField :label="t('dining.mergeMainTable')">
            <USelect
              :model-value="merging?.id"
              :items="freeTables"
              value-key="value"
              class="w-full"
              @update:model-value="pickMain($event as string)"
            />
          </UFormField>

          <UFormField :label="t('dining.mergeMain')">
            <USelect
              v-model="mergeWith"
              :items="mergeCandidates"
              value-key="value"
              multiple
              class="w-full"
            />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="merging = null"
            />
            <UButton
              :disabled="mergeWith.length === 0"
              :loading="dining.saving.value"
              :label="t('dining.merge')"
              @click="confirmMerge"
            />
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>
