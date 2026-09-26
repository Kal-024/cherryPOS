<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { type RefundableLine, useRefund } from '../composables/useRefund'
import { useOperator } from '../composables/useOperator'
import { firstApiErrorMessage } from '../composables/httpClient'
import { amount } from '../utils/money'
import type { Sale } from '../composables/useCart'

/**
 * Devolver contra el ticket original (H2.6, P-04).
 *
 * El recorrido es el del mostrador: el cliente llega con su comprobante, se
 * busca por número, se marca lo que trae de vuelta y se le entrega el dinero.
 *
 * **Sin ticket no se empieza.** No es una preferencia de diseño: el ERP rechaza
 * la nota de crédito sin documento original, y esa respuesta viaja por la cola
 * asíncrona — llegaría cuando el dinero ya salió del cajón. Por eso la pantalla
 * no ofrece siquiera un camino alternativo.
 *
 * **Y no se devuelve dos veces lo mismo.** Cada línea muestra lo que queda por
 * devolver, ya descontado lo de devoluciones anteriores: sin esa cuenta, tres
 * devoluciones parciales de una unidad vacían un ticket de dos.
 *
 * Al confirmar no se cobra acá: se abre la venta de devolución y la pantalla de
 * caja sigue con el panel de pago de siempre, en negativo. Una sola forma de
 * cerrar una venta es lo que hace que una devolución cuadre igual que un ticket.
 */
const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{ started: [Sale] }>()

const { t } = useI18n()
const refund = useRefund()
const { can } = useOperator()

const term = ref('')
const error = ref('')
const supervisorCode = ref('')
const supervisorPin = ref('')
const searchInput = ref<{ focus: () => void } | null>(null)

/** Quien no tiene el permiso puede armarla igual: la firma la pone el supervisor. */
const needsAuthorization = computed(() => !can('pos_sale.refund'))

const canConfirm = computed(() =>
  refund.hasSomething.value &&
  (!needsAuthorization.value || (supervisorCode.value !== '' && supervisorPin.value !== ''))
)

watch(open, async (isOpen) => {
  if (!isOpen) return

  term.value = ''
  error.value = ''
  supervisorCode.value = ''
  supervisorPin.value = ''
  refund.forget()

  await nextTick()
  searchInput.value?.focus()
})

async function search() {
  if (term.value.trim() === '') return

  error.value = ''

  try {
    await refund.search(term.value.trim())

    // Un solo resultado es el caso normal: se abre sin pedir un clic más.
    if (refund.matches.value.length === 1) {
      await choose(refund.matches.value[0]!.id)
    } else if (refund.matches.value.length === 0) {
      error.value = t('refund.notFound', { term: term.value })
    }
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

async function choose(saleId: string) {
  error.value = ''

  try {
    await refund.load(saleId)

    if (refund.original.value?.fully_returned) {
      error.value = t('refund.alreadyReturned')
    }
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

/** Lo que queda por devolver de una línea, para el tope del campo. */
function pending(line: RefundableLine): number {
  return Number(line.refundable)
}

async function confirm() {
  error.value = ''

  try {
    const sale = await refund.start(
      needsAuthorization.value
        ? { code: supervisorCode.value, pin: supervisorPin.value }
        : undefined
    )

    emit('started', sale)
    open.value = false
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="t('refund.title')">
    <template #body>
      <div class="flex flex-col gap-3">
        <!-- El número que el cliente trae impreso. Sin ticket no hay devolución. -->
        <UFormField :label="t('refund.ticket')" :hint="t('refund.ticketHint')">
          <div class="flex gap-2">
            <UInput
              ref="searchInput"
              v-model="term"
              class="grow"
              :placeholder="t('refund.ticketPlaceholder')"
              autofocus
              @keydown.enter.prevent="search"
            />
            <UButton
              icon="i-lucide-search"
              :loading="refund.searching.value"
              :label="t('refund.search')"
              @click="search"
            />
          </div>
        </UFormField>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          :title="error"
        />

        <!-- Varios resultados: se elige cuál. -->
        <div v-if="refund.matches.value.length > 1" class="flex flex-col gap-1">
          <button
            v-for="match in refund.matches.value"
            :key="match.id"
            type="button"
            class="flex items-center justify-between rounded-lg border border-default p-2 text-sm hover:border-primary"
            @click="choose(match.id)"
          >
            <span class="font-medium">{{ match.number }}</span>
            <span class="pos-amount">{{ amount(match.total) }}</span>
          </button>
        </div>

        <template v-if="refund.original.value">
          <div class="flex items-center justify-between">
            <span class="font-medium">{{ refund.original.value.sale.number }}</span>
            <UButton
              size="sm"
              color="neutral"
              variant="subtle"
              :label="t('refund.takeAll')"
              @click="refund.takeAll()"
            />
          </div>

          <table class="w-full text-sm">
            <thead class="text-muted">
              <tr>
                <th class="p-1 text-left">
                  {{ t('refund.product') }}
                </th>
                <th class="p-1 text-right">
                  {{ t('refund.sold') }}
                </th>
                <th class="p-1 text-right">
                  {{ t('refund.pending') }}
                </th>
                <th class="p-1 text-right">
                  {{ t('refund.returning') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="line in refund.original.value.lines"
                :key="line.id"
                class="border-t border-default"
              >
                <td class="p-1">
                  {{ line.description }}
                </td>
                <td class="pos-amount p-1 text-right">
                  {{ line.qty }}
                </td>
                <!-- Lo que queda: ya descontado lo devuelto antes. -->
                <td class="pos-amount p-1 text-right" :class="pending(line) === 0 ? 'text-muted' : ''">
                  {{ line.refundable }}
                </td>
                <td class="p-1 text-right">
                  <UInput
                    v-model="refund.chosen.value[line.id]"
                    type="number"
                    size="sm"
                    :min="0"
                    :max="pending(line)"
                    :disabled="pending(line) === 0"
                    class="w-24"
                  />
                </td>
              </tr>
            </tbody>
          </table>

          <div class="flex items-baseline justify-between rounded-lg bg-elevated px-3 py-2">
            <span class="text-muted">{{ t('refund.toReturn') }}</span>
            <span class="pos-total">{{ amount(refund.total.value.toFixed(2)) }}</span>
          </div>

          <!-- Devolver es sacar plata del cajón: pide una segunda firma. -->
          <template v-if="needsAuthorization">
            <UAlert
              color="warning"
              variant="subtle"
              :title="t('refund.needsAuthorization')"
              :description="t('refund.needsAuthorizationHint')"
            />

            <div class="flex gap-2">
              <UFormField :label="t('supervisor.code')" class="grow">
                <UInput v-model="supervisorCode" v-only="'code'" />
              </UFormField>
              <UFormField :label="t('supervisor.pin')" class="grow">
                <UInput v-model="supervisorPin" v-only="'digits'" type="password" />
              </UFormField>
            </div>
          </template>
        </template>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          :label="t('common.cancel')"
          @click="open = false"
        />
        <UButton
          :disabled="!canConfirm"
          :loading="refund.saving.value"
          :label="t('refund.confirm')"
          @click="confirm"
        />
      </div>
    </template>
  </UModal>
</template>
