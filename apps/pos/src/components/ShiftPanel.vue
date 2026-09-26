<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useShift, type DenominationCount, type ShiftSummary } from '../composables/useShift'
import DenominationCounter from './DenominationCounter.vue'
import { amount } from '../utils/money'

/**
 * Apertura y cierre del turno (G-04, D-06, H4).
 *
 * **Nada se vende sin turno abierto**, así que esta es la primera pantalla del
 * día. Y el turno es del equipo: se abre una vez por caja, no una por cajero.
 *
 * Al cerrar, el corte muestra la diferencia **por moneda**. Un consolidado
 * escondería que sobran córdobas y faltan dólares.
 */
const emit = defineEmits<{ opened: [], closed: [ShiftSummary], cancel: [] }>()

const { t } = useI18n()
const shift = useShift()

const openingFloat = ref('0.00')
const counts = ref<DenominationCount[]>([])
const cut = ref<ShiftSummary | null>(null)
const error = ref('')
const busy = ref(false)

onMounted(async () => {
  await Promise.all([shift.refresh(), shift.loadDenominations()])
})

const countedTotal = computed(() =>
  counts.value.reduce((total, entry) => total + entry.count * Number(entry.denomination_value), 0)
)

async function open() {
  busy.value = true
  error.value = ''

  try {
    await shift.open(openingFloat.value || '0.00', counts.value)
    emit('opened')
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err)
  } finally {
    busy.value = false
  }
}

async function close() {
  busy.value = true
  error.value = ''

  try {
    cut.value = await shift.close(counts.value)
    emit('closed', cut.value)
  } catch (err) {
    error.value = err instanceof Error ? err.message : String(err)
  } finally {
    busy.value = false
  }
}

function differenceTone(difference: string): 'success' | 'warning' | 'error' {
  const value = Number(difference)

  if (value === 0) return 'success'

  // Sobrar no es lo mismo que faltar, aunque las dos descuadren: un faltante
  // es plata que no está.
  return value > 0 ? 'warning' : 'error'
}

function differenceLabel(difference: string): string {
  const value = Number(difference)

  if (value === 0) return t('shiftUi.balanced')

  return value > 0 ? t('shiftUi.over') : t('shiftUi.short')
}
</script>

<template>
  <div class="mx-auto w-full max-w-2xl space-y-4 p-4">
    <!-- Corte recién emitido: lo que el supervisor firma. -->
    <template v-if="cut">
      <h2 class="text-lg font-semibold">
        {{ t('shiftUi.cut') }} · {{ cut.shift.code }}
      </h2>

      <div
        v-for="currency in cut.by_currency"
        :key="currency.currency_code"
        class="rounded-lg border border-default p-3"
      >
        <div class="mb-1 flex items-center gap-2">
          <span class="font-semibold">{{ currency.currency_code }}</span>
          <UBadge :color="differenceTone(currency.difference)" variant="subtle">
            {{ differenceLabel(currency.difference) }}
          </UBadge>
        </div>
        <dl class="space-y-0.5 text-sm">
          <div class="flex justify-between">
            <dt class="text-muted">
              {{ t('shiftUi.expected') }}
            </dt>
            <dd class="pos-amount">
              {{ amount(currency.expected) }}
            </dd>
          </div>
          <div class="flex justify-between">
            <dt class="text-muted">
              {{ t('shiftUi.counted') }}
            </dt>
            <dd class="pos-amount">
              {{ amount(currency.counted) }}
            </dd>
          </div>
          <div class="flex justify-between font-semibold">
            <dt>{{ t('shiftUi.difference') }}</dt>
            <dd class="pos-amount">
              {{ amount(currency.difference) }}
            </dd>
          </div>
        </dl>
      </div>

      <!-- El cajón se cuenta una vez, pero se sabe quién vendió qué. -->
      <section v-if="cut.by_cashier.length > 0">
        <h3 class="mb-1 text-sm font-semibold">
          {{ t('shiftUi.byCashier') }}
        </h3>
        <ul class="divide-y divide-default text-sm">
          <li v-for="row in cut.by_cashier" :key="row.employee_code" class="flex py-1">
            <span class="grow">{{ row.employee_name }}</span>
            <span class="text-muted mr-3">{{ t('shiftUi.salesCount', { count: row.sales }) }}</span>
            <span class="pos-amount">{{ amount(row.total) }}</span>
          </li>
        </ul>
      </section>
    </template>

    <!-- Cierre: contar el cajón. -->
    <template v-else-if="shift.isOpen.value">
      <h2 class="text-lg font-semibold">
        {{ t('shiftUi.close') }} · {{ shift.code.value }}
      </h2>

      <DenominationCounter v-model="counts" :denominations="shift.denominations.value" />

      <div class="flex items-baseline justify-between rounded-lg bg-elevated px-4 py-3">
        <span class="text-muted">{{ t('shiftUi.counted') }}</span>
        <span class="pos-total">{{ amount(String(countedTotal)) }}</span>
      </div>

      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        :description="error"
      />

      <div class="flex gap-2">
        <UButton
          color="neutral"
          variant="subtle"
          size="xl"
          @click="emit('cancel')"
        >
          {{ t('common.cancel') }}
        </UButton>

        <UButton
          size="xl"
          block
          :loading="busy"
          icon="i-lucide-lock"
          @click="close"
        >
          {{ t('shiftUi.close') }}
        </UButton>
      </div>
    </template>

    <!-- Apertura: el fondo inicial. -->
    <template v-else>
      <div>
        <h2 class="text-lg font-semibold">
          {{ t('shiftUi.needsShift') }}
        </h2>
        <p class="text-muted text-sm">
          {{ t('shiftUi.needsShiftHint') }}
        </p>
      </div>

      <UFormField :label="t('shiftUi.openingFloat')">
        <UInput
          v-model="openingFloat"
          v-only="'decimal'"
          type="number"
          step="0.01"
          min="0"
          size="lg"
          autofocus
        />
      </UFormField>

      <DenominationCounter v-model="counts" :denominations="shift.denominations.value" />

      <UAlert
        v-if="error"
        color="error"
        variant="subtle"
        :description="error"
      />

      <UButton
        size="xl"
        block
        :loading="busy"
        icon="i-lucide-unlock"
        @click="open"
      >
        {{ t('shiftUi.open') }}
      </UButton>
    </template>
  </div>
</template>
