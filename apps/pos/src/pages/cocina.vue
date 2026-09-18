<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../composables/httpClient'
import { type KitchenTicket, useKitchen } from '../composables/useKitchen'
import { useOperator } from '../composables/useOperator'

/**
 * Pantalla de cocina — KDS (F1-B).
 *
 * Es una **pantalla de pared**, y eso decide todo su diseño: se lee a dos metros
 * de distancia y se toca con las manos ocupadas, así que tipografía grande,
 * tarjetas anchas y un solo botón por comanda. Sin cabecera de caja, sin menú y
 * sin diálogos de confirmación: marcar lista una comanda que no lo estaba se
 * arregla mirando el pase, no con un "¿está seguro?".
 *
 * Se refresca sola cada pocos segundos (G-13). Lo que lleva más tiempo esperando
 * va primero, que es como se despacha una cocina.
 */
definePage({ meta: { layout: false, permission: 'kitchen.display' } })

const { t } = useI18n()
const kitchen = useKitchen()
const { can } = useOperator()
const toast = useToast()

const destination = ref<string>('kitchen')

const canUpdate = computed(() => can('kitchen.update'))

const destinations = computed(() => [
  { label: t('kitchen.kitchen'), value: 'kitchen' },
  { label: t('kitchen.bar'), value: 'bar' }
])

onMounted(() => kitchen.start(destination.value))
onUnmounted(() => kitchen.stop())

async function pick(value: string) {
  destination.value = value
  kitchen.stop()
  kitchen.start(value)
}

/** El botón dice el siguiente paso, no el estado actual: se toca sin leer. */
function nextAction(ticket: KitchenTicket): { label: string, status: 'preparing' | 'ready' | 'served' } | null {
  if (ticket.status === 'queued') return { label: t('kitchen.start'), status: 'preparing' }
  if (ticket.status === 'preparing') return { label: t('kitchen.ready'), status: 'ready' }
  if (ticket.status === 'ready') return { label: t('kitchen.served'), status: 'served' }

  return null
}

function tone(ticket: KitchenTicket): string {
  if (ticket.status === 'ready') return 'border-success bg-success/10'

  // El atraso lo decide el servidor con el umbral del negocio (G-16): el reloj
  // de una pantalla de pared no es el de nadie, y un umbral cableado acá haría
  // que cada local tuviera que discutir con el código.
  if (ticket.is_late) return 'border-error bg-error/10'
  if (kitchen.waiting(ticket) >= 8) return 'border-warning bg-warning/10'

  return 'border-default'
}

/**
 * Cómo se llama el curso en el pase.
 *
 * Los tres primeros tienen nombre porque son los que existen en una carta;
 * del cuarto en adelante se numeran, que es lo que hace el mesero al cantarlos.
 */
function courseName(course: number): string {
  return course <= 3
    ? t(`kitchen.course${course}`)
    : t('kitchen.courseN', { course })
}

async function advance(ticket: KitchenTicket) {
  const action = nextAction(ticket)

  if (!action) return

  try {
    await kitchen.advance(ticket.id, action.status, destination.value)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex h-full flex-col gap-3 p-4">
    <div class="flex items-center gap-3">
      <h1 class="text-2xl font-semibold">
        {{ t('kitchen.title') }}
      </h1>

      <UButton
        v-for="option in destinations"
        :key="option.value"
        :color="destination === option.value ? 'primary' : 'neutral'"
        :variant="destination === option.value ? 'solid' : 'subtle'"
        size="lg"
        :label="option.label"
        @click="pick(option.value)"
      />

      <div class="grow" />

      <!--
        La salida. El KDS no tiene cabecera —cuelga de una pared y no la
        necesita—, pero sin este botón la única forma de volver a la caja es
        cerrar sesión, y la misma pantalla se usa en una tablet que el mesero
        lleva encima.
      -->
      <UButton
        to="/"
        icon="i-lucide-calculator"
        color="neutral"
        variant="ghost"
        size="lg"
        :label="t('nav.register')"
      />

      <UBadge color="neutral" variant="subtle" size="lg">
        {{ t('kitchen.count', { count: kitchen.tickets.value.length }) }}
      </UBadge>
    </div>

    <div
      v-if="kitchen.tickets.value.length === 0"
      class="text-muted flex grow flex-col items-center justify-center gap-2"
    >
      <UIcon name="i-lucide-utensils-crossed" class="size-12" />
      <p class="text-xl font-medium">
        {{ t('kitchen.empty') }}
      </p>
    </div>

    <!-- Tarjetas anchas: se leen a dos metros. -->
    <div v-else class="grid min-h-0 grow auto-rows-min gap-3 overflow-y-auto sm:grid-cols-2 xl:grid-cols-4">
      <article
        v-for="ticket in kitchen.tickets.value"
        :key="ticket.id"
        class="flex flex-col gap-2 rounded-xl border-2 p-3"
        :class="tone(ticket)"
      >
        <header class="flex items-baseline gap-2">
          <span class="text-3xl font-bold tabular-nums">#{{ ticket.number }}</span>
          <span class="text-muted grow truncate">
            {{ ticket.label }}
            <!-- El curso, junto a la mesa: la cocina emplata distinto una
                 entrada que un postre. -->
            <span v-if="ticket.course > 1" class="text-primary">· {{ courseName(ticket.course) }}</span>
          </span>
          <span class="text-xl font-semibold tabular-nums">
            {{ t('kitchen.minutes', { count: kitchen.waiting(ticket) }) }}
          </span>
        </header>

        <ul class="flex flex-col gap-2 text-lg">
          <li v-for="line in ticket.lines" :key="line.id">
            <span class="font-semibold tabular-nums">{{ Number(line.qty) }}×</span>
            {{ line.name }}

            <!-- Los modificadores van debajo y en otro tono: son lo que cambia
                 la preparación, no el nombre del plato. -->
            <span v-if="line.modifiers.length" class="text-primary block pl-6 text-base">
              {{ line.modifiers.join(' · ') }}
            </span>
            <span v-if="line.notes" class="text-warning block pl-6 text-base">
              {{ line.notes }}
            </span>
          </li>
        </ul>

        <p v-if="ticket.notes" class="text-muted text-base">
          {{ ticket.notes }}
        </p>

        <div class="grow" />

        <UButton
          v-if="canUpdate && nextAction(ticket)"
          size="xl"
          block
          :loading="kitchen.saving.value"
          :label="nextAction(ticket)!.label"
          @click="advance(ticket)"
        />
      </article>
    </div>
  </div>
</template>
