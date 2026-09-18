<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Sale } from '../composables/useCart'
import { amount } from '../utils/money'

/**
 * El total, en la esquina fija.
 *
 * *"Total en tipografía grande, esquina fija, siempre legible desde un metro."*
 * No es estética: el cajero lo canta de memoria mientras mira al cliente, y el
 * cliente lo lee desde el otro lado del mostrador.
 *
 * El desglose de arriba solo muestra lo que tiene valor: un descuento en cero o
 * un exento que no existe gastan espacio y confunden.
 */
const props = defineProps<{ sale: Sale | null }>()

const { t } = useI18n()

const hasDiscount = computed(() => Number(props.sale?.discount_total ?? 0) > 0)
const hasExempt = computed(() => Number(props.sale?.exempt_total ?? 0) > 0)
const hasRounding = computed(() => Number(props.sale?.cash_rounding ?? 0) !== 0)
const hasTip = computed(() => Number(props.sale?.tip_amount ?? 0) !== 0)

/**
 * Total más propina, que es lo único que el terminal suma de dos importes que
 * el servidor ya calculó — no una fórmula: ninguno de los dos lleva impuesto,
 * descuento ni redondeo encima.
 */
const amountDue = computed(() =>
  amount((Number(props.sale?.total ?? 0) + Number(props.sale?.tip_amount ?? 0)).toFixed(2))
)
</script>

<template>
  <div class="rounded-lg bg-elevated px-4 py-3">
    <dl v-if="sale" class="mb-2 space-y-0.5 text-sm">
      <div class="flex justify-between">
        <dt class="text-muted">
          {{ t('sale.subtotal') }}
        </dt>
        <dd class="pos-amount">
          {{ amount(sale.taxable_base) }}
        </dd>
      </div>
      <div v-if="hasExempt" class="flex justify-between">
        <dt class="text-muted">
          {{ t('sale.exempt') }}
        </dt>
        <dd class="pos-amount">
          {{ amount(sale.exempt_total) }}
        </dd>
      </div>
      <div v-if="hasDiscount" class="flex justify-between">
        <dt class="text-muted">
          {{ t('sale.discount') }}
        </dt>
        <dd class="pos-amount">
          −{{ amount(sale.discount_total) }}
        </dd>
      </div>
      <div class="flex justify-between">
        <dt class="text-muted">
          {{ t('sale.tax') }}
        </dt>
        <dd class="pos-amount">
          {{ amount(sale.tax_total) }}
        </dd>
      </div>
      <div v-if="hasRounding" class="flex justify-between">
        <dt class="text-muted">
          {{ t('sale.rounding') }}
        </dt>
        <dd class="pos-amount">
          {{ amount(sale.cash_rounding) }}
        </dd>
      </div>
    </dl>

    <div class="flex items-baseline justify-between border-t border-default pt-2">
      <span class="text-muted text-lg">{{ t('sale.total') }}</span>
      <span :class="hasTip ? 'pos-amount text-xl font-semibold' : 'pos-total'">
        {{ amount(sale?.total ?? '0.00') }}
      </span>
    </div>

    <!--
      Con propina, el número grande pasa a ser lo que hay que cobrar y el total
      fiscal queda arriba en tamaño normal. El cajero canta lo que le tienen que
      dar; el documento sigue diciendo lo que se vendió (G-16).
    -->
    <template v-if="hasTip">
      <div class="flex items-baseline justify-between">
        <span class="text-muted">{{ t('sale.tip') }}</span>
        <span class="pos-amount">{{ amount(sale?.tip_amount ?? '0.00') }}</span>
      </div>
      <div class="flex items-baseline justify-between">
        <span class="text-muted text-lg">{{ t('sale.amountDue') }}</span>
        <span class="pos-total">{{ amountDue }}</span>
      </div>
    </template>
  </div>
</template>
