<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { type ClosedShift, type Settlement, useShiftHistory } from '../../composables/useShiftHistory'
import type { ShiftSummary } from '../../composables/useShift'
import { useOperator } from '../../composables/useOperator'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { amount } from '../../utils/money'

/**
 * Las cajas cerradas y su descuadre (H4.3).
 *
 * Al cerrar el turno aparecía el faltante o el sobrante y ahí terminaba todo: el
 * turno quedaba descuadrado para siempre y ninguna pantalla volvía a mostrarlo.
 * Eso pesa más allá del POS — el ERP no emite el comprobante contable del día si
 * la caja no cuadra, así que un descuadre sin resolver frena la contabilidad.
 *
 * **Cuadrar no reescribe el arqueo** (P1). El conteo queda tal como se hizo y la
 * diferencia se salda con un movimiento de caja, con su motivo y el PIN del
 * supervisor —que es distinto del de sesión (P-11)—. Mover plata de un arqueo ya
 * cerrado es justo donde conviene el segundo freno.
 *
 * El importe no se teclea: es la diferencia que el corte calculó. Pedirlo a mano
 * abriría la puerta a cuadrar con un número redondo que no corresponde a nada.
 */
definePage({ meta: { layout: 'admin', permission: 'report.shift_cut' } })

const { t } = useI18n()
const history = useShiftHistory()
const { can } = useOperator()
const toast = useToast()

const pendingOnly = ref(false)
const selected = ref<ClosedShift | null>(null)
const cut = ref<(ShiftSummary & { settled: boolean, settlements: Settlement[] }) | null>(null)
const settling = ref(false)

const reason = ref('')
const supervisorCode = ref('')
const supervisorPin = ref('')

const canSettle = computed(() => can('pos_shift.settle'))

onMounted(refresh)

async function refresh() {
  try {
    await history.list(pendingOnly.value)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function select(shift: ClosedShift) {
  selected.value = shift
  cut.value = null

  try {
    cut.value = await history.summary(shift.id)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

/** Lo que hay que cuadrar, por moneda. Sobra positivo, falta negativo. */
function differences(shift: ClosedShift): CurrencyCutRow[] {
  return shift.by_currency.map((row) => ({
    currency: row.currency_code,
    difference: row.difference,
    short: Number(row.difference) < 0
  }))
}

interface CurrencyCutRow {
  currency: string
  difference: string
  short: boolean
}

async function confirmSettle() {
  if (!selected.value) return

  try {
    cut.value = await history.settle(
      selected.value.id,
      reason.value,
      supervisorCode.value,
      supervisorPin.value
    ) as ShiftSummary & { settled: boolean, settlements: Settlement[] }

    settling.value = false
    reason.value = ''
    supervisorCode.value = ''
    supervisorPin.value = ''

    await refresh()
    toast.add({ title: t('cashboxes.settled'), color: 'success' })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function printCut(shift: ClosedShift) {
  try {
    await history.openCut(shift.id)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center gap-3">
      <USwitch
        v-model="pendingOnly"
        :label="t('cashboxes.pendingOnly')"
        @update:model-value="refresh"
      />

      <!-- El aviso no se apaga mientras quede una caja sin cuadrar: es la
           condición para que el ERP pueda cerrar el día. -->
      <UBadge v-if="history.pending.value.length > 0" color="warning" variant="subtle">
        {{ t('cashboxes.pendingCount', { count: history.pending.value.length }) }}
      </UBadge>
      <UBadge v-else color="success" variant="subtle">
        {{ t('cashboxes.allSettled') }}
      </UBadge>
    </div>

    <div
      v-if="!history.loading.value && history.shifts.value.length === 0"
      class="text-muted flex flex-col items-center gap-1 rounded-lg border border-default p-10 text-center"
    >
      <UIcon name="i-lucide-archive" class="size-8" />
      <p class="font-medium">
        {{ t('cashboxes.empty') }}
      </p>
    </div>

    <div v-else class="overflow-x-auto rounded-lg border border-default">
      <table class="w-full text-sm">
        <thead class="bg-elevated text-muted">
          <tr>
            <th class="p-2 text-left">
              {{ t('cashboxes.code') }}
            </th>
            <th class="p-2 text-left">
              {{ t('cashboxes.closedAt') }}
            </th>
            <th class="p-2 text-right">
              {{ t('cashboxes.difference') }}
            </th>
            <th class="p-2 text-left">
              {{ t('cashboxes.state') }}
            </th>
            <th class="p-2" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="shift in history.shifts.value"
            :key="shift.id"
            class="border-t border-default"
            :class="selected?.id === shift.id ? 'bg-elevated' : ''"
          >
            <td class="p-2 font-medium">
              {{ shift.code }}
            </td>
            <td class="p-2">
              {{ shift.closed_at }}
            </td>
            <td class="pos-amount p-2 text-right">
              <span
                v-for="row in differences(shift)"
                :key="row.currency"
                class="block"
                :class="Number(row.difference) === 0 ? '' : row.short ? 'text-error' : 'text-warning'"
              >
                {{ row.currency }} {{ amount(row.difference) }}
              </span>
            </td>
            <td class="p-2">
              <UBadge :color="shift.settled ? 'success' : 'warning'" size="sm" variant="subtle">
                {{ shift.settled ? t('cashboxes.settledState') : t('cashboxes.pendingState') }}
              </UBadge>
            </td>
            <td class="p-2">
              <div class="flex justify-end gap-1">
                <UButton
                  icon="i-lucide-eye"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  :aria-label="t('cashboxes.detail')"
                  @click="select(shift)"
                />
                <UButton
                  icon="i-lucide-printer"
                  color="neutral"
                  variant="ghost"
                  size="sm"
                  :aria-label="t('cashboxes.printCut')"
                  @click="printCut(shift)"
                />
                <UButton
                  v-if="!shift.settled && canSettle"
                  icon="i-lucide-scale"
                  color="primary"
                  variant="subtle"
                  size="sm"
                  :label="t('cashboxes.settle')"
                  @click="select(shift); settling = true"
                />
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- El corte completo: es lo que el resumen devolvía y no tenía pantalla. -->
    <div v-if="cut" class="flex flex-col gap-3 rounded-lg border border-default p-3">
      <h2 class="font-semibold">
        {{ t('cashboxes.detail') }} · {{ cut.shift.code }}
      </h2>

      <div class="grid gap-3 md:grid-cols-2">
        <div>
          <h3 class="text-muted mb-1 text-sm">
            {{ t('cashboxes.byCurrency') }}
          </h3>
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="row in cut.by_currency" :key="row.currency_code" class="border-t border-default">
                <td class="py-1">
                  {{ row.currency_code }}
                </td>
                <td class="pos-amount py-1 text-right">
                  {{ amount(row.expected) }}
                </td>
                <td class="pos-amount py-1 text-right">
                  {{ amount(row.counted) }}
                </td>
                <td
                  class="pos-amount py-1 text-right"
                  :class="Number(row.difference) === 0 ? 'text-success' : 'text-error'"
                >
                  {{ amount(row.difference) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div>
          <h3 class="text-muted mb-1 text-sm">
            {{ t('cashboxes.byCashier') }}
          </h3>
          <table class="w-full text-sm">
            <tbody>
              <tr v-for="row in cut.by_cashier" :key="row.employee_code" class="border-t border-default">
                <td class="py-1">
                  {{ row.employee_name }}
                </td>
                <td class="py-1 text-right">
                  {{ row.sales }}
                </td>
                <td class="pos-amount py-1 text-right">
                  {{ amount(row.total) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Los ajustes quedan a la vista: un descuadre saldado se sigue viendo,
           que es la diferencia entre cuadrar y tapar. -->
      <div v-if="cut.settlements.length > 0">
        <h3 class="text-muted mb-1 text-sm">
          {{ t('cashboxes.settlements') }}
        </h3>
        <ul class="text-sm">
          <li v-for="entry in cut.settlements" :key="entry.id" class="border-t border-default py-1">
            <span :class="entry.direction === 'out' ? 'text-error' : 'text-warning'">
              {{ entry.direction === 'out' ? '−' : '+' }}{{ amount(entry.amount) }} {{ entry.currency_code }}
            </span>
            · {{ entry.reason }}
          </li>
        </ul>
      </div>
    </div>

    <UModal v-model:open="settling" :title="t('cashboxes.settle')">
      <template #body>
        <div class="flex flex-col gap-3">
          <UAlert
            color="info"
            variant="subtle"
            :title="t('cashboxes.settleHint')"
            :description="t('cashboxes.settleHintDetail')"
          />

          <UFormField :label="t('cashboxes.reason')" :hint="t('cashboxes.reasonHint')">
            <UInput v-model="reason" />
          </UFormField>

          <UFormField :label="t('supervisor.code')">
            <UInput v-model="supervisorCode" />
          </UFormField>

          <UFormField :label="t('supervisor.pin')" :hint="t('supervisor.pinHint')">
            <UInput v-model="supervisorPin" type="password" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton
            color="neutral"
            variant="ghost"
            :label="t('common.cancel')"
            @click="settling = false"
          />
          <UButton
            :loading="history.saving.value"
            :disabled="!reason || !supervisorCode || !supervisorPin"
            :label="t('cashboxes.settle')"
            @click="confirmSettle"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
