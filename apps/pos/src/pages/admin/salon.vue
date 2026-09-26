<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { type DiningTable, useDining } from '../../composables/useDining'
import { GRID, snap, useFloorCanvas } from '../../composables/useFloorCanvas'
import { firstApiErrorMessage } from '../../composables/httpClient'

/**
 * El plano del salón, donde se dibuja una vez (F1-B, §10).
 *
 * El servidor sabía hacer todo esto desde el primer día —crear zonas y mesas,
 * moverlas, darlas de baja— y **no había pantalla**: el plano se sembraba a mano
 * en la base, mientras el mensaje de "sin mesas" ya remitía a una administración
 * que no existía.
 *
 * Es configuración del supervisor (`dining.manage`), no del mesero: se hace al
 * instalar y se retoca cuando el local cambia de muebles. Por eso vive en la
 * trastienda y no en la pantalla de servicio.
 *
 * **Las zonas son pestañas, no rectángulos.** Dibujarlas como contenedores
 * pediría guardar su tamaño y su posición, y hoy la tabla no los tiene: sería
 * una migración para un problema que el filtro ya resuelve.
 */
definePage({ meta: { layout: 'admin', permission: 'dining.manage' } })

const { t } = useI18n()
const dining = useDining()
const toast = useToast()

const surface = ref<HTMLElement | null>(null)
const canvas = useFloorCanvas(surface)

const selectedArea = ref<string | null>(null)
const selected = ref<DiningTable | null>(null)
const addingArea = ref(false)
const addingTable = ref(false)

const areaDraft = ref({ code: '', name: '' })
const tableDraft = ref({ code: '', name: '', seats: 4, shape: 'square' as DiningTable['shape'] })

onMounted(async () => {
  try {
    await dining.refresh()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
})

const visible = computed(() =>
  dining.tables.value.filter(
    (table) => selectedArea.value === null || table.area_id === selectedArea.value
  )
)

const shapeOptions = computed(() =>
  (['square', 'round', 'rect'] as const).map((value) => ({
    label: t(`floorPlan.shape.${value}`),
    value
  }))
)

/* ---------------------------------------------------------------- arrastrar */

/**
 * Tamaños de fábrica al crear una mesa, en píxeles de la rejilla.
 *
 * El rectángulo nace rectangular: hasta ahora `rect` existía en la base y se
 * dibujaba igual que el cuadrado, porque sin ancho y alto propios no hay forma de
 * que uno se vea distinto del otro.
 */
const DEFAULT_SIZE: Record<DiningTable['shape'], { width: number, height: number }> = {
  square: { width: 140, height: 140 },
  round: { width: 140, height: 140 },
  rect: { width: 240, height: 120 }
}

/** Una mesa más chica que esto no se puede tocar; más grande no es una mesa. */
const MIN_SIDE = 60
const MAX_SIDE = 600

let dragging: {
  id: string
  offsetX: number
  offsetY: number
  fromX: number
  fromY: number
} | null = null

let resizing: {
  id: string
  fromWidth: number
  fromHeight: number
} | null = null

/**
 * ¿Se pisa con otra mesa?
 *
 * Dos mesas en el mismo sitio no existen en un local, y en la pantalla producen
 * algo peor que un plano feo: la de abajo queda **tapada y sin poder tocarse**,
 * así que el mesero no puede abrir su cuenta y nada explica por qué.
 *
 * Se comparan **rectángulos de verdad** y no distancias entre centros: desde que
 * cada mesa tiene su tamaño, una larga y una chica se pisan a distancias que una
 * sola constante no puede describir.
 */
function overlaps(table: DiningTable, x: number, y: number, width: number, height: number): boolean {
  return visible.value.some(
    (other) =>
      other.id !== table.id &&
      x < other.pos_x + other.width &&
      x + width > other.pos_x &&
      y < other.pos_y + other.height &&
      y + height > other.pos_y
  )
}

function startDrag(event: PointerEvent, table: DiningTable) {
  const point = canvas.toFloor(event.clientX, event.clientY)

  dragging = {
    id: table.id,
    offsetX: point.x - table.pos_x,
    offsetY: point.y - table.pos_y,
    fromX: table.pos_x,
    fromY: table.pos_y
  }
  selected.value = table
  ;(event.currentTarget as HTMLElement)?.setPointerCapture?.(event.pointerId)
}

function onDrag(event: PointerEvent) {
  if (!dragging) return

  const point = canvas.toFloor(event.clientX, event.clientY)

  // Se ajusta a la rejilla **mientras** se arrastra y no al soltar: ver la mesa
  // saltar de posición al levantar el dedo se siente como un error.
  dining.placeLocally(
    dragging.id,
    Math.max(0, snap(point.x - dragging.offsetX)),
    Math.max(0, snap(point.y - dragging.offsetY))
  )
}

/**
 * Al soltar se guarda, una sola vez.
 *
 * Un `PUT` por píxel recorrido llenaría la bitácora de ruido y la red de viajes
 * inútiles para un dato que solo importa en su valor final.
 */
async function endDrag() {
  if (!dragging) return

  const moved = dining.tables.value.find((table) => table.id === dragging?.id)
  const from = { x: dragging.fromX, y: dragging.fromY }
  dragging = null

  if (!moved) return

  // Encima de otra no se queda: vuelve de donde salió y se dice por qué.
  if (overlaps(moved, moved.pos_x, moved.pos_y, moved.width, moved.height)) {
    dining.placeLocally(moved.id, from.x, from.y)
    toast.add({ title: t('floorPlan.occupied'), color: 'warning' })

    return
  }

  try {
    await dining.updateTable(moved.id, { pos_x: moved.pos_x, pos_y: moved.pos_y })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
    await dining.refresh()
  }
}

/* ------------------------------------------------------------- redimensionar */

/**
 * Estirar la mesa desde su esquina.
 *
 * Es el mismo gesto que moverla, y por eso no hay dos campos numéricos: se
 * arrastra la esquina y se ve el resultado. Configurar el plano es una tarea de
 * una sola vez y tiene que ser cómoda, no precisa al píxel — la rejilla se
 * encarga de la precisión.
 *
 * **Solo acá.** En el salón operativo el tamaño no se toca: el mesero une y
 * separa mesas, y estirar el mueble desde la pantalla de servicio sería cambiar
 * la configuración del local con el cliente sentado.
 */
function startResize(event: PointerEvent, table: DiningTable) {
  resizing = { id: table.id, fromWidth: table.width, fromHeight: table.height }
  selected.value = table
  ;(event.currentTarget as HTMLElement)?.setPointerCapture?.(event.pointerId)
}

function onResize(event: PointerEvent) {
  if (!resizing) return

  const table = dining.tables.value.find((item) => item.id === resizing?.id)

  if (!table) return

  const point = canvas.toFloor(event.clientX, event.clientY)

  // La esquina sigue al dedo, ajustada a la rejilla mientras se arrastra: ver el
  // tamaño saltar al soltar se siente como un error.
  dining.resizeLocally(
    table.id,
    Math.min(MAX_SIDE, Math.max(MIN_SIDE, snap(point.x - table.pos_x))),
    Math.min(MAX_SIDE, Math.max(MIN_SIDE, snap(point.y - table.pos_y)))
  )
}

async function endResize() {
  if (!resizing) return

  const table = dining.tables.value.find((item) => item.id === resizing?.id)
  const from = { width: resizing.fromWidth, height: resizing.fromHeight }
  resizing = null

  if (!table) return

  // Crecer encima de la vecina la deja tapada igual que moverse encima: se
  // devuelve al tamaño anterior y se dice por qué.
  if (overlaps(table, table.pos_x, table.pos_y, table.width, table.height)) {
    dining.resizeLocally(table.id, from.width, from.height)
    toast.add({ title: t('floorPlan.occupied'), color: 'warning' })

    return
  }

  if (table.width === from.width && table.height === from.height) return

  try {
    await dining.updateTable(table.id, { width: table.width, height: table.height })
    selected.value = dining.tables.value.find((item) => item.id === table.id) ?? null
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
    await dining.refresh()
  }
}

/* ------------------------------------------------------------------ acciones */

async function addArea() {
  try {
    await dining.createArea({ code: areaDraft.value.code, name: areaDraft.value.name })
    addingArea.value = false
    areaDraft.value = { code: '', name: '' }
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function addTable() {
  try {
    // Nace arriba a la izquierda del área visible: colocarla es arrastrarla, y
    // pedir coordenadas en un formulario sería pedirlas dos veces.
    await dining.createTable({
      ...tableDraft.value,
      ...DEFAULT_SIZE[tableDraft.value.shape],
      area_id: selectedArea.value,
      pos_x: GRID * 2,
      pos_y: GRID * 2
    })

    addingTable.value = false
    tableDraft.value = { code: '', name: '', seats: 4, shape: 'square' }
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function saveSelected(changes: Partial<DiningTable>) {
  if (!selected.value) return

  const id = selected.value.id

  try {
    await dining.updateTable(id, changes)
    await dining.refresh()
    selected.value = dining.tables.value.find((table) => table.id === id) ?? null
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function removeSelected() {
  if (!selected.value) return

  try {
    await dining.removeTable(selected.value.id)
    selected.value = null
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-col gap-3">
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

      <UButton
        icon="i-lucide-folder-plus"
        color="neutral"
        variant="ghost"
        size="sm"
        :label="t('floorPlan.addArea')"
        @click="addingArea = true"
      />

      <div class="grow" />

      <UButton
        icon="i-lucide-plus"
        size="sm"
        :label="t('floorPlan.addTable')"
        @click="addingTable = true"
      />

      <!--
        Perderse en el lienzo es fácil; volver tiene que serlo más.

        Los tres miden lo mismo **a la fuerza**: el del medio lleva texto y los
        de los lados ícono, y con la altura librada al contenido el porcentaje
        quedaba montado más abajo que sus vecinos. Ancho fijo y centrado propio
        para que el número pueda ir de "70%" a "150%" sin mover a los otros dos.
      -->
      <UButtonGroup size="sm" class="items-center">
        <UButton
          icon="i-lucide-zoom-out"
          color="neutral"
          variant="subtle"
          :aria-label="t('floorPlan.zoomOut')"
          @click="canvas.zoomBy(-0.1)"
        />
        <UButton
          color="neutral"
          variant="subtle"
          class="w-16 justify-center tabular-nums"
          :title="t('floorPlan.zoomReset')"
          :label="`${Math.round(canvas.zoom.value * 100)}%`"
          @click="canvas.reset()"
        />
        <UButton
          icon="i-lucide-zoom-in"
          color="neutral"
          variant="subtle"
          :aria-label="t('floorPlan.zoomIn')"
          @click="canvas.zoomBy(0.1)"
        />
      </UButtonGroup>
    </div>

    <!-- Altura propia: el área de la trastienda hace su propio desplazamiento y
         no reparte altura entre sus hijos, así que `grow` no daba ninguna y el
         plano quedaba recortado a una franja. -->
    <div class="flex h-[32rem] gap-3">
      <!--
        El lienzo. El fondo de puntos es la rejilla a la que se ajustan las
        mesas: sin una referencia visible, "alineado" queda a ojo y el plano se
        ve torcido justo en la pantalla que hay que leer de lejos.
      -->
      <div
        ref="surface"
        class="relative h-full grow touch-none overflow-hidden rounded-lg border border-default bg-elevated/30"
        :style="{
          backgroundImage: 'radial-gradient(currentColor 1px, transparent 1px)',
          backgroundSize: `${GRID * canvas.zoom.value}px ${GRID * canvas.zoom.value}px`,
          backgroundPosition: `${canvas.panX.value}px ${canvas.panY.value}px`,
          color: 'var(--ui-border-accented)'
        }"
        @pointerdown="canvas.startPan"
        @pointermove="canvas.movePan"
        @pointerup="canvas.endPan"
        @pointercancel="canvas.endPan"
        @wheel="canvas.onWheel"
      >
        <div
          class="absolute left-0 top-0 origin-top-left"
          :style="{ transform: canvas.transform.value }"
        >
          <!-- El tamaño sale del dato, no de una clase: por eso el rectángulo
               puede verse rectangular y la larga del fondo, larga. -->
          <div
            v-for="table in visible"
            :key="table.id"
            class="absolute"
            :style="{
              left: `${table.pos_x}px`,
              top: `${table.pos_y}px`,
              width: `${table.width}px`,
              height: `${table.height}px`
            }"
          >
            <button
              :data-plan-table="table.code"
              type="button"
              class="flex size-full cursor-grab touch-none flex-col items-center justify-center gap-0.5 border-2 bg-default p-2 text-sm active:cursor-grabbing"
              :class="[
                selected?.id === table.id ? 'border-primary' : 'border-default',
                table.shape === 'round' ? 'rounded-full' : 'rounded-lg'
              ]"
              @pointerdown.stop="startDrag($event, table)"
              @pointermove="onDrag"
              @pointerup="endDrag"
              @pointercancel="endDrag"
            >
              <span class="font-semibold">{{ table.name ?? table.code }}</span>
              <span class="text-muted text-xs">{{ t('dining.seats', { count: table.seats }) }}</span>
            </button>

            <!--
              El tirador, solo en la elegida: uno por mesa en todo el plano sería
              un campo de puntos que se tocan sin querer al arrastrar.

              Mismo gesto que mover, así que no hay dos campos numéricos que
              llenar: se estira la esquina y se ve el resultado.
            -->
            <button
              v-if="selected?.id === table.id"
              type="button"
              :data-plan-resize="table.code"
              class="bg-primary absolute -right-1.5 -bottom-1.5 size-4 cursor-nwse-resize touch-none rounded-sm border-2 border-default"
              :aria-label="t('floorPlan.resize')"
              :title="t('floorPlan.size', { width: table.width, height: table.height })"
              @pointerdown.stop="startResize($event, table)"
              @pointermove="onResize"
              @pointerup="endResize"
              @pointercancel="endResize"
            />
          </div>
        </div>

        <p
          v-if="visible.length === 0"
          class="text-muted absolute inset-0 flex items-center justify-center text-sm"
        >
          {{ t('floorPlan.empty') }}
        </p>
      </div>

      <!-- La ficha de la mesa elegida. Arrastrar coloca; lo demás se teclea. -->
      <aside v-if="selected" class="flex w-64 shrink-0 flex-col gap-3">
        <UFormField :label="t('floorPlan.code')">
          <UInput
            v-only="'code'"
            :model-value="selected.code"
            @change="saveSelected({ code: ($event.target as HTMLInputElement).value })"
          />
        </UFormField>

        <UFormField :label="t('floorPlan.name')">
          <UInput
            :model-value="selected.name ?? ''"
            @change="saveSelected({ name: ($event.target as HTMLInputElement).value })"
          />
        </UFormField>

        <UFormField :label="t('floorPlan.seats')">
          <UInput
            v-only="'digits'"
            type="number"
            :model-value="selected.seats"
            @change="saveSelected({ seats: Number(($event.target as HTMLInputElement).value) })"
          />
        </UFormField>

        <UFormField :label="t('floorPlan.shape.label')">
          <USelect
            :model-value="selected.shape"
            :items="shapeOptions"
            @update:model-value="saveSelected({ shape: $event as DiningTable['shape'] })"
          />
        </UFormField>

        <div class="grow" />

        <!-- Baja lógica: las ventas de ayer la referencian y el histórico tiene
             que seguir diciendo en qué mesa se sirvió. -->
        <UButton
          icon="i-lucide-trash-2"
          color="error"
          variant="subtle"
          block
          :loading="dining.saving.value"
          :label="t('floorPlan.removeTable')"
          @click="removeSelected"
        />
      </aside>
    </div>

    <UModal v-model:open="addingArea" :title="t('floorPlan.addArea')">
      <template #body>
        <div class="flex flex-col gap-3">
          <UFormField :label="t('floorPlan.code')">
            <UInput v-model="areaDraft.code" v-only="'code'" />
          </UFormField>
          <UFormField :label="t('floorPlan.name')">
            <UInput v-model="areaDraft.name" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            :label="t('common.cancel')"
            @click="addingArea = false"
          />
          <UButton :loading="dining.saving.value" :label="t('common.confirm')" @click="addArea" />
        </div>
      </template>
    </UModal>

    <UModal v-model:open="addingTable" :title="t('floorPlan.addTable')">
      <template #body>
        <div class="flex flex-col gap-3">
          <UFormField :label="t('floorPlan.code')">
            <UInput v-model="tableDraft.code" v-only="'code'" />
          </UFormField>
          <UFormField :label="t('floorPlan.name')">
            <UInput v-model="tableDraft.name" />
          </UFormField>
          <UFormField :label="t('floorPlan.seats')">
            <UInput v-model="tableDraft.seats" v-only="'digits'" type="number" />
          </UFormField>
          <UFormField :label="t('floorPlan.shape.label')">
            <USelect v-model="tableDraft.shape" :items="shapeOptions" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            :label="t('common.cancel')"
            @click="addingTable = false"
          />
          <UButton :loading="dining.saving.value" :label="t('common.confirm')" @click="addTable" />
        </div>
      </template>
    </UModal>
  </div>
</template>
