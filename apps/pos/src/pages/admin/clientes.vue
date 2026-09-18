<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import {
  type Customer,
  type CustomerDraft,
  customerDraftOf,
  emptyCustomerDraft,
  useCustomers
} from '../../composables/useCustomers'
import { useOperator } from '../../composables/useOperator'
import CustomerForm from '../../components/CustomerForm.vue'
import CreditPanel from '../../components/CreditPanel.vue'

/**
 * Clientes (H8.1).
 *
 * **En caja solo se usan clientes ya registrados** (Q-03): esta es la pantalla
 * donde nacen. El crédito se abre desde la misma ficha porque son la misma
 * conversación —"quiero llevar fiado"— y separarlos obligaría a buscar dos veces
 * al mismo cliente.
 */
definePage({ meta: { layout: 'admin', permission: 'customer.read' } })

const { t } = useI18n()
const customers = useCustomers()
const { can } = useOperator()
const toast = useToast()

const search = ref('')
const kind = ref<string | null>(null)
const includeInactive = ref(false)

const open = ref(false)
const editingId = ref<string | null>(null)
const draft = ref<CustomerDraft>(emptyCustomerDraft())
const formError = ref('')

const creditFor = ref<Customer | null>(null)

const canCreate = computed(() => can('customer.create'))
const canUpdate = computed(() => can('customer.update'))
const canSeeCredit = computed(() => can('credit.read'))

const kindOptions = computed(() => [
  { label: t('customers.allKinds'), value: null },
  { label: t('customers.kindCash'), value: 'cash' },
  { label: t('customers.kindAccount'), value: 'account' }
])

onMounted(refresh)

let debounce: ReturnType<typeof setTimeout> | undefined

watch([search, kind, includeInactive], () => {
  clearTimeout(debounce)
  debounce = setTimeout(refresh, 250)
})

async function refresh() {
  try {
    await customers.list({
      search: search.value.trim(),
      kind: kind.value,
      includeInactive: includeInactive.value
    })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

function startCreate() {
  editingId.value = null
  formError.value = ''
  draft.value = emptyCustomerDraft()
  open.value = true
}

async function startEdit(customer: Customer) {
  formError.value = ''

  try {
    // La ficha completa trae la persona: el listado no siempre la necesita y
    // editar sobre datos parciales borraría teléfono y dirección.
    const full = await customers.find(customer.id)
    editingId.value = full.id
    draft.value = customerDraftOf(full)
    open.value = true
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function submit() {
  formError.value = ''

  try {
    if (editingId.value) {
      await customers.update(editingId.value, draft.value)
      toast.add({ title: t('customers.updated'), color: 'success' })
    } else {
      await customers.create(draft.value)
      toast.add({ title: t('customers.created'), color: 'success' })
    }

    open.value = false
    await refresh()
  } catch (err) {
    formError.value = firstApiErrorMessage(err)
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-end gap-2">
      <UFormField :label="t('customers.search')" class="grow">
        <UInput
          v-model="search"
          icon="i-lucide-search"
          :placeholder="t('customers.searchPlaceholder')"
          class="w-full"
        />
      </UFormField>

      <UFormField :label="t('customers.kind')">
        <USelect v-model="kind" :items="kindOptions" class="w-44" />
      </UFormField>

      <USwitch v-model="includeInactive" :label="t('customers.includeInactive')" class="pb-2" />

      <UButton
        v-if="canCreate"
        icon="i-lucide-user-plus"
        :label="t('customers.new')"
        class="ml-auto"
        @click="startCreate"
      />
    </div>

    <div
      v-if="!customers.loading.value && customers.customers.value.length === 0"
      class="text-muted flex flex-col items-center gap-1 rounded-lg border border-default p-10 text-center"
    >
      <UIcon name="i-lucide-users" class="size-8" />
      <p class="font-medium">
        {{ t('customers.empty') }}
      </p>
      <p class="text-sm">
        {{ t('customers.emptyHint') }}
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
            <th class="px-2 py-1 font-medium">
              {{ t('customers.kind') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('customers.phone') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('customers.status') }}
            </th>
            <th class="w-24" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="customer in customers.customers.value"
            :key="customer.id"
            class="border-t border-default hover:bg-elevated/60"
            :class="{ 'opacity-60': !customer.is_active }"
          >
            <td class="px-2 py-1">
              {{ customer.name }}
            </td>
            <td class="text-muted px-2 py-1 font-mono text-xs">
              {{ customer.person?.national_id ?? '—' }}
            </td>
            <td class="px-2 py-1">
              {{ customer.kind === 'account' ? t('customers.kindAccount') : t('customers.kindCash') }}
            </td>
            <td class="text-muted px-2 py-1">
              {{ customer.person?.phone ?? '—' }}
            </td>
            <td class="px-2 py-1">
              <UBadge v-if="!customer.is_active" color="neutral" variant="subtle">
                {{ t('customers.inactive') }}
              </UBadge>
              <UBadge v-else-if="customer.credit_account?.is_blocked" color="error" variant="subtle">
                {{ t('credit.blocked') }}
              </UBadge>
              <UBadge v-else-if="customer.is_tax_exempt" color="info" variant="subtle">
                {{ t('customers.exempt') }}
              </UBadge>
            </td>
            <td class="px-2 py-1 text-right">
              <UButton
                v-if="canUpdate"
                icon="i-lucide-pencil"
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="t('customers.edit')"
                @click="startEdit(customer)"
              />
              <UButton
                v-if="canSeeCredit"
                icon="i-lucide-credit-card"
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="t('credit.title')"
                @click="creditFor = customer"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UModal v-model:open="open" :title="editingId ? t('customers.edit') : t('customers.new')">
      <template #body>
        <div class="flex flex-col gap-3">
          <UAlert
            v-if="formError"
            color="error"
            variant="subtle"
            :title="formError"
          />

          <CustomerForm
            v-model="draft"
            :saving="customers.saving.value"
            :editing="editingId !== null"
            @submit="submit"
            @cancel="open = false"
          />
        </div>
      </template>
    </UModal>

    <UModal
      :open="creditFor !== null"
      :title="creditFor ? t('credit.titleFor', { name: creditFor.name }) : ''"
      @update:open="(value: boolean) => { if (!value) creditFor = null }"
    >
      <template #body>
        <CreditPanel
          v-if="creditFor"
          :customer-id="creditFor.id"
          :customer-name="creditFor.name"
          @changed="refresh"
        />
      </template>
    </UModal>
  </div>
</template>
