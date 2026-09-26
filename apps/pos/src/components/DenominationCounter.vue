<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Denomination, DenominationCount } from '../composables/useShift'
import { amount } from '../utils/money'

/**
 * El conteo billete por billete (D-06).
 *
 * Es lo que convierte *"falta plata"* en *"faltan tres billetes de 50"*, que es
 * la diferencia entre una sospecha y un dato.
 *
 * Las denominaciones vienen del servidor y son **configurables por país**: la
 * caja de Nicaragua no cuenta lo mismo que la de otro mercado, y cablear la
 * lista sería garantizar que el segundo país no funcione.
 */
const props = defineProps<{ denominations: Denomination[] }>()
const model = defineModel<DenominationCount[]>({ required: true })

const { t } = useI18n()

const counts = ref<Record<string, number>>({})

function key(denomination: Denomination): string {
  return `${denomination.currency_code}:${denomination.value}`
}

const byCurrency = computed(() => {
  const groups: Record<string, Denomination[]> = {}

  for (const denomination of props.denominations) {
    groups[denomination.currency_code] ??= []
    groups[denomination.currency_code]!.push(denomination)
  }

  return groups
})

const totals = computed(() => {
  const sums: Record<string, number> = {}

  for (const denomination of props.denominations) {
    const quantity = counts.value[key(denomination)] ?? 0
    sums[denomination.currency_code] =
      (sums[denomination.currency_code] ?? 0) + quantity * Number(denomination.value)
  }

  return sums
})

watch(
  counts,
  () => {
    model.value = props.denominations
      .map((denomination) => ({
        denomination_value: denomination.value,
        currency_code: denomination.currency_code,
        count: counts.value[key(denomination)] ?? 0
      }))
      .filter((entry) => entry.count > 0)
  },
  { deep: true }
)
</script>

<template>
  <div class="space-y-4">
    <section v-for="(group, currency) in byCurrency" :key="currency">
      <!-- Cada moneda se cuenta por separado: un total consolidado escondería
           que sobran córdobas y faltan dólares (Q-06). -->
      <h4 class="mb-1 text-sm font-semibold">
        {{ currency }}
      </h4>

      <table class="w-full text-sm">
        <thead class="text-muted text-left">
          <tr>
            <th class="py-1 font-medium">
              {{ t('shiftUi.denomination') }}
            </th>
            <th class="w-24 py-1 font-medium">
              {{ t('shiftUi.quantity') }}
            </th>
            <th class="w-28 py-1 text-right font-medium">
              {{ t('shiftUi.subtotal') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="denomination in group" :key="denomination.id" class="border-t border-default">
            <td class="py-1">
              {{ amount(denomination.value) }}
              <span class="text-muted ml-1 text-xs">
                {{ denomination.kind === 'bill' ? '🧾' : '🪙' }}
              </span>
            </td>
            <td class="py-1">
              <UInput
                v-model.number="counts[key(denomination)]"
                v-only="'digits'"
                type="number"
                min="0"
                size="sm"
                :placeholder="'0'"
              />
            </td>
            <td class="pos-amount py-1 text-right">
              {{ amount(String((counts[key(denomination)] ?? 0) * Number(denomination.value))) }}
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-default font-semibold">
            <td class="py-1" colspan="2">
              {{ t('shiftUi.counted') }}
            </td>
            <td class="pos-amount py-1 text-right">
              {{ amount(String(totals[currency] ?? 0)) }}
            </td>
          </tr>
        </tfoot>
      </table>
    </section>
  </div>
</template>
