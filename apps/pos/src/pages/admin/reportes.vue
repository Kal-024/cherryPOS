<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { useReports } from '../../composables/useReports'
import { amount } from '../../utils/money'

/**
 * Ventas del día por cajero y por terminal (P-07).
 *
 * Son dos preguntas distintas y por eso dos tablas: **quién vendió cuánto** es
 * de personas —y de comisiones, y de conversaciones incómodas— y **qué caja
 * movió cuánto** es del arqueo del cajón. Con relevos dentro del mismo turno
 * (D-05) los dos cortes no coinciden, así que un solo agrupamiento dejaría una
 * de las dos sin respuesta.
 *
 * El exento va separado de la base gravada porque el libro de ventas los
 * declara distinto: juntarlos ahorraría una línea y costaría la declaración.
 */
definePage({ meta: { layout: 'admin', permission: 'report.daily_sales' } })

const { t } = useI18n()
const reports = useReports()
const toast = useToast()

const today = new Date().toISOString().slice(0, 10)
const from = ref(today)
const to = ref(today)

onMounted(refresh)

watch([from, to], refresh)

async function refresh() {
  try {
    await reports.dailySales(from.value, to.value)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-end gap-2">
      <UFormField :label="t('reports.from')">
        <UInput v-model="from" type="date" class="w-40" />
      </UFormField>

      <UFormField :label="t('reports.to')">
        <UInput v-model="to" type="date" class="w-40" />
      </UFormField>
    </div>

    <div v-if="reports.sales.value" class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
      <div class="rounded-lg border border-default p-2">
        <span class="text-muted block text-xs">{{ t('reports.salesCount') }}</span>
        <span class="tabular-nums">{{ reports.sales.value.totals.sales_count }}</span>
      </div>
      <div class="rounded-lg border border-default p-2">
        <span class="text-muted block text-xs">{{ t('reports.total') }}</span>
        <span class="font-medium tabular-nums">{{ amount(reports.sales.value.totals.total) }}</span>
      </div>
      <div class="rounded-lg border border-default p-2">
        <span class="text-muted block text-xs">{{ t('reports.taxableBase') }}</span>
        <span class="tabular-nums">{{ amount(reports.sales.value.totals.taxable_base) }}</span>
      </div>
      <div class="rounded-lg border border-default p-2">
        <span class="text-muted block text-xs">{{ t('reports.exempt') }}</span>
        <span class="tabular-nums">{{ amount(reports.sales.value.totals.exempt_total) }}</span>
      </div>
      <div class="rounded-lg border border-default p-2">
        <span class="text-muted block text-xs">{{ t('reports.tax') }}</span>
        <span class="tabular-nums">{{ amount(reports.sales.value.totals.tax_total) }}</span>
      </div>
    </div>

    <div v-if="reports.sales.value" class="grid gap-4 lg:grid-cols-2">
      <div class="overflow-x-auto rounded-lg border border-default">
        <p class="text-muted border-b border-default px-2 py-1 text-xs">
          {{ t('reports.byEmployee') }}
        </p>
        <table class="w-full text-sm">
          <tbody>
            <tr
              v-for="row in reports.sales.value.by_employee"
              :key="row.employee_id"
              class="border-t border-default"
            >
              <td class="px-2 py-1">
                {{ row.name }} <span class="text-muted text-xs">{{ row.code }}</span>
              </td>
              <td class="text-muted px-2 py-1 text-right tabular-nums">
                {{ t('reports.salesN', { count: row.sales_count }) }}
              </td>
              <td class="px-2 py-1 text-right tabular-nums">
                {{ amount(row.discount_total) }}
              </td>
              <td class="px-2 py-1 text-right font-medium tabular-nums">
                {{ amount(row.total) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="overflow-x-auto rounded-lg border border-default">
        <p class="text-muted border-b border-default px-2 py-1 text-xs">
          {{ t('reports.byTerminal') }}
        </p>
        <table class="w-full text-sm">
          <tbody>
            <tr
              v-for="row in reports.sales.value.by_terminal"
              :key="row.terminal_id"
              class="border-t border-default"
            >
              <td class="px-2 py-1">
                {{ row.name }} <span class="text-muted text-xs">{{ row.code }}</span>
              </td>
              <td class="text-muted px-2 py-1 text-right tabular-nums">
                {{ t('reports.salesN', { count: row.sales_count }) }}
              </td>
              <td class="px-2 py-1 text-right font-medium tabular-nums">
                {{ amount(row.total) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <p v-if="reports.sales.value?.totals.sales_count === 0" class="text-muted text-sm">
      {{ t('reports.empty') }}
    </p>
  </div>
</template>
