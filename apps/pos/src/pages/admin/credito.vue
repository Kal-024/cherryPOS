<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { type CreditAccountRow, useCredit } from '../../composables/useCredit'
import { amount } from '../../utils/money'
import CreditPanel from '../../components/CreditPanel.vue'

/**
 * Créditos y saldos: uno de los cinco reportes del mínimo operativo de F1 (P-07).
 *
 * Responde la pregunta con la que abre el supervisor —**a quién hay que
 * llamar**—, así que ordena por saldo descendente, que es como la trae la API, y
 * deja a mano los dos filtros que importan: quién debe y quién está bloqueado.
 */
definePage({ meta: { layout: 'admin', permission: 'credit.read' } })

const { t } = useI18n()
const credit = useCredit()
const toast = useToast()

/**
 * Un filtro, no dos interruptores.
 *
 * Eran dos `USwitch` independientes y el servidor los encadena con `AND`: con
 * los dos encendidos la lista mostraba cuentas bloqueadas **y** con saldo, que
 * no es lo que promete un rótulo que empieza con "Solo". Dos opciones que se
 * excluyen se piden con un control que las excluya.
 */
type CreditFilter = 'all' | 'with_balance' | 'blocked'

const filter = ref<CreditFilter>('with_balance')
const selected = ref<CreditAccountRow | null>(null)

const filterOptions = computed(() => [
  { label: t('credit.filterAll'), value: 'all' },
  { label: t('credit.withBalanceOnly'), value: 'with_balance' },
  { label: t('credit.blockedOnly'), value: 'blocked' }
])

onMounted(refresh)

watch(filter, refresh)

async function refresh() {
  try {
    await credit.list({
      withBalanceOnly: filter.value === 'with_balance',
      blockedOnly: filter.value === 'blocked'
    })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center gap-4">
      <URadioGroup
        v-model="filter"
        :items="filterOptions"
        orientation="horizontal"
      />
    </div>

    <div
      v-if="!credit.loading.value && credit.accounts.value.length === 0"
      class="text-muted flex flex-col items-center gap-1 rounded-lg border border-default p-10 text-center"
    >
      <UIcon name="i-lucide-credit-card" class="size-8" />
      <p class="font-medium">
        {{ t('credit.empty') }}
      </p>
    </div>

    <div v-else class="overflow-x-auto rounded-lg border border-default">
      <table class="w-full text-sm">
        <thead class="bg-elevated text-muted">
          <tr class="text-left">
            <th class="px-2 py-1 font-medium">
              {{ t('customers.name') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('customers.nationalId') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('credit.limit') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('credit.balance') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('credit.available') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('credit.cutOffDay') }}
            </th>
            <th class="w-10" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="account in credit.accounts.value"
            :key="account.id"
            class="cursor-pointer border-t border-default hover:bg-elevated/60"
            @click="selected = account"
          >
            <td class="px-2 py-1">
              {{ account.customer_name }}
              <UBadge
                v-if="account.is_blocked"
                color="error"
                variant="subtle"
                class="ml-1"
              >
                {{ t('credit.blocked') }}
              </UBadge>
            </td>
            <td class="text-muted px-2 py-1 font-mono text-xs">
              {{ account.national_id ?? '—' }}
            </td>
            <td class="px-2 py-1 text-right tabular-nums">
              {{ amount(account.credit_limit) }}
            </td>
            <td class="px-2 py-1 text-right font-medium tabular-nums">
              {{ amount(account.balance) }}
            </td>
            <td class="px-2 py-1 text-right tabular-nums">
              {{ amount(account.available) }}
            </td>
            <td class="text-muted px-2 py-1">
              {{ account.cut_off_day }}
            </td>
            <td class="px-2 py-1 text-right">
              <UIcon name="i-lucide-chevron-right" class="text-muted size-4" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UModal
      :open="selected !== null"
      :title="selected ? t('credit.titleFor', { name: selected.customer_name }) : ''"
      @update:open="(value: boolean) => { if (!value) selected = null }"
    >
      <template #body>
        <CreditPanel
          v-if="selected"
          :customer-id="selected.customer_id"
          :customer-name="selected.customer_name"
          @changed="refresh"
        />
      </template>
    </UModal>
  </div>
</template>
