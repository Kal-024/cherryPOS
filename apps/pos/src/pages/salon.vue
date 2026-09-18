<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { firstApiErrorMessage } from '../composables/httpClient'
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

const canServe = computed(() => can('dining.serve'))

const visible = computed(() =>
  dining.tables.value.filter((table) => !selectedArea.value || table.area_id === selectedArea.value)
)

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
         mesa está donde está en el local. -->
    <div v-else class="min-h-0 grow overflow-auto rounded-lg border border-default bg-elevated/30 p-4">
      <div class="relative" style="min-height: 32rem; min-width: 32rem;">
        <button
          v-for="table in visible"
          :key="table.id"
          type="button"
          class="absolute flex w-28 flex-col items-center justify-center gap-0.5 border-2 p-2 text-sm transition"
          :class="[
            tone(table),
            table.shape === 'round' ? 'rounded-full h-28' : 'rounded-lg h-24'
          ]"
          :style="{ left: `${table.pos_x}px`, top: `${table.pos_y}px` }"
          @click="tap(table)"
        >
          <span class="font-semibold">{{ table.name ?? table.code }}</span>

          <template v-if="table.sale">
            <span class="pos-amount text-xs">{{ amount(table.sale.total) }}</span>
            <span class="text-xs">
              {{ t('dining.minutes', { count: dining.elapsedMinutes(table) ?? 0 }) }}
            </span>
            <span v-if="table.sale.guests" class="text-muted text-xs">
              {{ t('dining.guests', { count: table.sale.guests }) }}
            </span>

            <!-- El estado del pedido, en la mesa: el mesero mira el salón, no el
                 pase de la cocina (G-16). -->
            <span v-if="table.sale.kitchen" class="text-xs font-medium">
              {{ kitchenLabel(table.sale.kitchen.state) }}
            </span>
          </template>

          <span v-else-if="table.state === 'merged'" class="text-xs">
            {{ t('dining.merged') }}
          </span>

          <span v-else class="text-muted text-xs">
            {{ t('dining.seats', { count: table.seats }) }}
          </span>
        </button>
      </div>
    </div>

    <div v-if="canServe" class="flex flex-wrap items-center gap-2">
      <UButton
        icon="i-lucide-link"
        color="neutral"
        variant="subtle"
        size="sm"
        :disabled="visible.length < 2"
        :label="t('dining.merge')"
        @click="merging = visible.find((table) => table.state === 'free') ?? null"
      />

      <UButton
        v-for="table in visible.filter((item) => item.merged_into_id === null && dining.tables.value.some((other) => other.merged_into_id === item.id))"
        :key="`split-${table.id}`"
        icon="i-lucide-unlink"
        color="neutral"
        variant="ghost"
        size="sm"
        :label="t('dining.splitOf', { code: table.name ?? table.code })"
        @click="split(table)"
      />
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
      :open="merging !== null"
      :title="t('dining.merge')"
      @update:open="(value: boolean) => { if (!value) { merging = null; mergeWith = [] } }"
    >
      <template #body>
        <div class="flex flex-col gap-3">
          <p class="text-muted text-sm">
            {{ t('dining.mergeHint') }}
          </p>

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
