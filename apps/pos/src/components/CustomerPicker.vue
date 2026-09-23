<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { type Customer, useCustomers } from '../composables/useCustomers'
import { firstApiErrorMessage } from '../composables/httpClient'

/**
 * A quién se le vende (Q-03, P-03).
 *
 * La caja no tenía forma de decirlo. La API aceptaba el cliente **solo al abrir
 * la venta**, así que en la pantalla no había manera de cargarle la cuenta a
 * nadie: el cajero elegía "crédito" como medio de pago y recién al cerrar
 * aparecía un "el cliente no tiene cuenta de crédito abierta" que no explicaba
 * cómo arreglarlo.
 *
 * **Solo clientes ya registrados.** Crear es del supervisor, y el día que entre
 * el ERP la creación pasa allá. Por eso acá no hay formulario de alta: hay un
 * buscador.
 *
 * Se busca contra el servidor y no contra la caché local —como sí hace el
 * catálogo (D-04)— porque la cédula y el saldo cambian en la trastienda
 * mientras la caja vende, y un cliente bloqueado que aparece disponible sería
 * peor que esperar el viaje.
 */
const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{ selected: Customer | null }>()
const emit = defineEmits<{ choose: [Customer | null] }>()

const { t } = useI18n()
const customers = useCustomers()

const search = ref('')
const error = ref('')
const searchInput = ref<{ focus: () => void } | null>(null)

let debounce: ReturnType<typeof setTimeout> | undefined

watch(open, async (isOpen) => {
  if (!isOpen) return

  search.value = ''
  error.value = ''
  await refresh()
  await nextTick()
  searchInput.value?.focus()
})

watch(search, () => {
  clearTimeout(debounce)
  debounce = setTimeout(refresh, 300)
})

async function refresh() {
  error.value = ''

  try {
    await customers.list({ search: search.value || undefined })
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

const rows = computed(() => customers.customers.value)

function choose(customer: Customer | null) {
  emit('choose', customer)
  open.value = false
}

/** Lo que hace falta saber de un vistazo: si puede comprar fiado y cuánto. */
function creditHint(customer: Customer): string | null {
  const account = customer.credit_account

  if (!account) return null
  if (account.is_blocked) return t('customerPicker.blocked')

  return t('customerPicker.available', {
    amount: (Number(account.credit_limit) - Number(account.balance)).toFixed(2)
  })
}
</script>

<template>
  <UModal v-model:open="open" :title="t('customerPicker.title')">
    <template #body>
      <div class="flex flex-col gap-3">
        <UInput
          ref="searchInput"
          v-model="search"
          icon="i-lucide-search"
          :placeholder="t('customerPicker.searchHint')"
          autofocus
        />

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          :title="error"
        />

        <p v-else-if="rows.length === 0" class="text-muted py-6 text-center text-sm">
          {{ t('customerPicker.empty') }}
        </p>

        <div v-else class="flex max-h-80 flex-col gap-1 overflow-auto">
          <button
            v-for="customer in rows"
            :key="customer.id"
            type="button"
            class="flex items-center justify-between gap-3 rounded-lg border border-default p-2 text-left text-sm hover:border-primary"
            @click="choose(customer)"
          >
            <span class="flex flex-col">
              <span class="font-medium">{{ customer.name }}</span>
              <span v-if="customer.person?.national_id" class="text-muted text-xs">
                {{ customer.person.national_id }}
              </span>
            </span>

            <span class="flex items-center gap-2">
              <UBadge
                v-if="customer.is_tax_exempt"
                color="info"
                size="sm"
                variant="subtle"
              >
                {{ t('customerPicker.exempt') }}
              </UBadge>
              <UBadge
                v-if="creditHint(customer)"
                :color="customer.credit_account?.is_blocked ? 'error' : 'success'"
                size="sm"
                variant="subtle"
              >
                {{ creditHint(customer) }}
              </UBadge>
            </span>
          </button>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-between">
        <UButton
          v-if="props.selected"
          color="neutral"
          variant="ghost"
          icon="i-lucide-user-x"
          :label="t('customerPicker.clear')"
          @click="choose(null)"
        />
        <div class="grow" />
        <UButton
          color="neutral"
          variant="ghost"
          :label="t('common.cancel')"
          @click="open = false"
        />
      </div>
    </template>
  </UModal>
</template>
