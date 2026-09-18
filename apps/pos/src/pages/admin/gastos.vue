<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import {
  type Expense,
  type ExpenseDraft,
  emptyExpenseDraft,
  useExpenses
} from '../../composables/useExpenses'
import { useOperator } from '../../composables/useOperator'
import { useShift } from '../../composables/useShift'
import { amount } from '../../utils/money'

/**
 * Gastos del local (B-14, H4).
 *
 * *"Sin esto el POS reporta ingresos, no utilidad."*
 *
 * Tres cosas que la pantalla sostiene:
 *
 *  - **Pagar del cajón exige turno abierto.** No es una validación caprichosa:
 *    ese gasto sale como movimiento de caja y el arqueo tiene que poder
 *    explicar la plata que falta.
 *  - **El impuesto se captura aparte**, porque el IVA de un gasto es crédito
 *    fiscal, no costo.
 *  - **Un gasto no se edita**: se anula con motivo y queda a la vista anulado
 *    (P1).
 */
definePage({ meta: { layout: 'admin', permission: 'expense.read' } })

const { t } = useI18n()
const expenses = useExpenses()
const shift = useShift()
const { can } = useOperator()
const toast = useToast()

const today = new Date().toISOString().slice(0, 10)
const from = ref(today)
const to = ref(today)
const categoryId = ref<string | null>(null)

const open = ref(false)
const draft = ref<ExpenseDraft>(emptyExpenseDraft())
const formError = ref('')

const voiding = ref<Expense | null>(null)
const voidReason = ref('')

const canCreate = computed(() => can('expense.create'))
const canVoid = computed(() => can('expense.void'))

// `isOpen` y no `code`: una terminal con el turno ya cerrado conserva el código
// del turno en memoria, y pagar del cajón de un turno cerrado es justo lo que
// deja el arqueo sin explicación.
const hasShift = computed(() => shift.isOpen.value)
const needsShift = computed(() => draft.value.payment_method === 'cash' && !hasShift.value)

const categoryOptions = computed(() => [
  { label: t('expenses.allCategories'), value: null },
  ...expenses.categories.value.map((category) => ({ label: category.name, value: category.id }))
])

// La categoría abre vacía: clasificar el gasto es la razón de ser del registro
// —un gasto sin categoría reporta ingresos, no utilidad— y preseleccionar la
// primera haría que todo termine en "Alquiler".
const formCategoryOptions = computed(() => [
  { label: t('expenses.chooseCategory'), value: null },
  ...expenses.categories.value.map((category) => ({
    label: `${category.name} · ${t(`expenses.behaviour.${category.behaviour}`)}`,
    value: category.id
  }))
])

const methodOptions = computed(() =>
  (['cash', 'card', 'transfer', 'credit', 'other'] as const).map((method) => ({
    label: t(`expenses.method.${method}`),
    value: method
  }))
)

onMounted(async () => {
  // El turno se relee al entrar: esta pantalla puede abrirse desde otra
  // terminal o después de que el cajero cerró caja.
  await Promise.all([
    expenses.loadCategories().catch(() => undefined),
    shift.refresh().catch(() => undefined)
  ])

  await refresh()
})

let debounce: ReturnType<typeof setTimeout> | undefined

watch([from, to, categoryId], () => {
  clearTimeout(debounce)
  debounce = setTimeout(refresh, 250)
})

async function refresh() {
  try {
    await expenses.list({ from: from.value, to: to.value, categoryId: categoryId.value })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

function startCreate() {
  formError.value = ''
  draft.value = emptyExpenseDraft()
  open.value = true
}

async function submit() {
  formError.value = ''

  try {
    await expenses.record(draft.value)
    toast.add({ title: t('expenses.recorded'), color: 'success' })
    open.value = false
    await refresh()
  } catch (err) {
    formError.value = firstApiErrorMessage(err)
  }
}

async function confirmVoid() {
  if (!voiding.value) return

  try {
    await expenses.voidExpense(voiding.value.id, voidReason.value)
    toast.add({ title: t('expenses.voided'), color: 'success' })
    voiding.value = null
    voidReason.value = ''
    await refresh()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-end gap-2">
      <UFormField :label="t('expenses.from')">
        <UInput v-model="from" type="date" class="w-40" />
      </UFormField>

      <UFormField :label="t('expenses.to')">
        <UInput v-model="to" type="date" class="w-40" />
      </UFormField>

      <UFormField :label="t('expenses.category')">
        <USelect v-model="categoryId" :items="categoryOptions" class="w-56" />
      </UFormField>

      <UButton
        v-if="canCreate"
        icon="i-lucide-receipt"
        :label="t('expenses.new')"
        class="ml-auto"
        @click="startCreate"
      />
    </div>

    <div
      v-if="!expenses.loading.value && expenses.expenses.value.length === 0"
      class="text-muted flex flex-col items-center gap-1 rounded-lg border border-default p-10 text-center"
    >
      <UIcon name="i-lucide-receipt" class="size-8" />
      <p class="font-medium">
        {{ t('expenses.empty') }}
      </p>
      <p class="text-sm">
        {{ t('expenses.emptyHint') }}
      </p>
    </div>

    <div v-else class="overflow-x-auto rounded-lg border border-default">
      <table class="w-full text-sm">
        <thead class="bg-elevated text-muted">
          <tr class="text-left">
            <th class="px-2 py-1 font-medium">
              {{ t('expenses.date') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('expenses.category') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('expenses.description') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('expenses.method.label') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('expenses.amount') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('expenses.tax') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('expenses.total') }}
            </th>
            <th class="w-10" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="expense in expenses.expenses.value"
            :key="expense.id"
            class="border-t border-default hover:bg-elevated/60"
            :class="{ 'opacity-60 line-through': expense.status === 'voided' }"
          >
            <td class="px-2 py-1">
              {{ expense.document_date }}
            </td>
            <td class="px-2 py-1">
              {{ expense.category?.name }}
            </td>
            <td class="px-2 py-1">
              {{ expense.description }}
              <span v-if="expense.void_reason" class="text-muted block text-xs no-underline">
                {{ t('expenses.voidedFor', { reason: expense.void_reason }) }}
              </span>
            </td>
            <td class="text-muted px-2 py-1">
              {{ t(`expenses.method.${expense.payment_method}`) }}
            </td>
            <td class="px-2 py-1 text-right tabular-nums">
              {{ amount(expense.amount) }}
            </td>
            <td class="px-2 py-1 text-right tabular-nums">
              {{ amount(expense.tax_amount) }}
            </td>
            <td class="px-2 py-1 text-right font-medium tabular-nums">
              {{ amount(expense.total) }}
            </td>
            <td class="px-2 py-1 text-right">
              <UButton
                v-if="canVoid && expense.status !== 'voided'"
                icon="i-lucide-ban"
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="t('expenses.void')"
                @click="voiding = expense"
              />
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr class="border-t border-default bg-elevated">
            <td colspan="6" class="px-2 py-1 text-right font-medium">
              {{ t('expenses.periodTotal') }}
            </td>
            <td class="px-2 py-1 text-right font-medium tabular-nums">
              {{ amount(expenses.total.value) }}
            </td>
            <td />
          </tr>
        </tfoot>
      </table>
    </div>

    <UModal v-model:open="open" :title="t('expenses.new')">
      <template #body>
        <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="submit">
          <UAlert
            v-if="formError"
            color="error"
            variant="subtle"
            :title="formError"
            class="sm:col-span-2"
          />

          <!-- Pagar del cajón sin turno abierto no es un descuido que se pueda
               tolerar: ese gasto es un movimiento de caja y no habría caja donde
               anotarlo. -->
          <UAlert
            v-if="needsShift"
            color="warning"
            variant="subtle"
            :title="t('expenses.needsShift')"
            :description="t('expenses.needsShiftHint')"
            class="sm:col-span-2"
          />

          <UFormField :label="t('expenses.category')" required>
            <USelect v-model="draft.category_id" :items="formCategoryOptions" class="w-full" />
          </UFormField>

          <UFormField :label="t('expenses.method.label')" required>
            <USelect v-model="draft.payment_method" :items="methodOptions" class="w-full" />
          </UFormField>

          <UFormField :label="t('expenses.description')" required class="sm:col-span-2">
            <UInput v-model="draft.description" autofocus class="w-full" />
          </UFormField>

          <UFormField :label="t('expenses.amount')" required :help="t('expenses.amountHint')">
            <UInput v-model="draft.amount" inputmode="decimal" class="w-full" />
          </UFormField>

          <UFormField :label="t('expenses.tax')" :help="t('expenses.taxHint')">
            <UInput v-model="draft.tax_amount" inputmode="decimal" class="w-full" />
          </UFormField>

          <UFormField :label="t('expenses.documentNumber')" :help="t('expenses.documentNumberHint')">
            <UInput v-model="draft.document_number" class="w-full" />
          </UFormField>

          <UFormField :label="t('expenses.documentDate')">
            <UInput v-model="draft.document_date" type="date" class="w-full" />
          </UFormField>

          <div class="flex justify-end gap-2 sm:col-span-2">
            <UButton
              type="button"
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="open = false"
            />
            <UButton
              type="submit"
              :loading="expenses.saving.value"
              :disabled="needsShift"
              :label="t('expenses.record')"
            />
          </div>
        </form>
      </template>
    </UModal>

    <UModal
      :open="voiding !== null"
      :title="t('expenses.void')"
      @update:open="(value: boolean) => { if (!value) { voiding = null; voidReason = '' } }"
    >
      <template #body>
        <div class="flex flex-col gap-3">
          <p class="text-sm">
            {{ t('expenses.voidHint') }}
          </p>

          <UFormField :label="t('expenses.voidReason')" required>
            <UInput v-model="voidReason" autofocus class="w-full" />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="voiding = null"
            />
            <UButton
              color="error"
              :disabled="voidReason.trim() === ''"
              :loading="expenses.saving.value"
              :label="t('expenses.void')"
              @click="confirmVoid"
            />
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>
