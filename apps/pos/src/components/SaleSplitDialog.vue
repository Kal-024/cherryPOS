<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SaleLine } from '../composables/useCart'
import type { DiningTable } from '../composables/useDining'
import { amount } from '../utils/money'

/**
 * Traspasar o dividir una cuenta (F1-B).
 *
 * Las dos operaciones comparten pantalla porque comparten el gesto: elegir qué
 * líneas y decir a dónde van. Lo que cambia es el destino —otra mesa o una
 * cuenta nueva— y esa elección se hace al final, cuando el mesero ya sabe qué
 * está moviendo.
 *
 * **Sin líneas elegidas se mueve la cuenta entera.** Es el caso más común —el
 * grupo se cambió de mesa— y obligar a marcar todas las líneas para eso sería
 * un toque por plato.
 */
const props = defineProps<{ lines: SaleLine[], tables: DiningTable[], busy?: boolean }>()

const emit = defineEmits<{
  transfer: [{ tableId: string, lines: string[] }]
  split: [string[]]
  cancel: []
}>()

const { t } = useI18n()

const chosen = ref<Set<string>>(new Set())
// Cadena vacía y no `null`: el selector distingue "todavía no elegí" de una
// mesa concreta, y con `null` el tipo del componente no cierra.
const targetTable = ref('')

const selectedTotal = computed(() =>
  props.lines
    .filter((line) => chosen.value.has(line.id))
    .reduce((total, line) => total + Number.parseFloat(line.total), 0)
    .toFixed(2)
)

const movingAll = computed(() => chosen.value.size === 0 || chosen.value.size === props.lines.length)

/** Dividir deja las dos cuentas en la mesa: mover todo no dividiría nada. */
const canSplit = computed(() => chosen.value.size > 0 && chosen.value.size < props.lines.length)

const tableOptions = computed(() =>
  props.tables
    .filter((table) => table.state !== 'merged')
    .map((table) => ({
      label: table.state === 'occupied'
        ? t('transfer.tableBusy', { code: table.name ?? table.code })
        : (table.name ?? table.code),
      value: table.id
    }))
)

function toggle(lineId: string) {
  const next = new Set(chosen.value)

  if (next.has(lineId)) next.delete(lineId)
  else next.add(lineId)

  chosen.value = next
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <p class="text-muted text-sm">
      {{ t('transfer.hint') }}
    </p>

    <ul class="flex flex-col gap-1">
      <li v-for="line in props.lines" :key="line.id">
        <button
          type="button"
          class="flex w-full items-center gap-2 rounded-md border border-default px-2 py-1 text-left text-sm"
          :class="chosen.has(line.id) ? 'border-primary bg-primary/10' : ''"
          @click="toggle(line.id)"
        >
          <UIcon
            :name="chosen.has(line.id) ? 'i-lucide-check-circle' : 'i-lucide-circle'"
            class="size-4 shrink-0"
            :class="chosen.has(line.id) ? 'text-primary' : 'text-muted'"
          />
          <span class="grow truncate">{{ line.description }}</span>
          <span class="text-muted text-xs tabular-nums">{{ Number(line.qty) }}×</span>
          <span class="pos-amount text-xs">{{ amount(line.total) }}</span>
        </button>
      </li>
    </ul>

    <p class="text-sm">
      {{ movingAll ? t('transfer.movingAll') : t('transfer.movingSome', { amount: amount(selectedTotal) }) }}
    </p>

    <UFormField :label="t('transfer.toTable')" :help="t('transfer.toTableHint')">
      <USelect v-model="targetTable" :items="tableOptions" class="w-full" />
    </UFormField>

    <div class="flex flex-wrap justify-end gap-2">
      <UButton
        color="neutral"
        variant="ghost"
        :label="t('common.cancel')"
        @click="emit('cancel')"
      />

      <UButton
        color="neutral"
        variant="subtle"
        :disabled="!canSplit"
        :loading="props.busy"
        :label="t('transfer.split')"
        @click="emit('split', [...chosen])"
      />

      <UButton
        :disabled="targetTable === ''"
        :loading="props.busy"
        :label="t('transfer.move')"
        @click="emit('transfer', { tableId: targetTable, lines: [...chosen] })"
      />
    </div>
  </div>
</template>
