<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../composables/httpClient'
import { MAX_AUTHORIZED, type CreditDetail, type Statement, useCredit } from '../composables/useCredit'
import { useOperator } from '../composables/useOperator'
import { amount } from '../utils/money'

/**
 * La cuenta de crédito de un cliente (H8).
 *
 * Reúne en una pantalla lo que el supervisor necesita para decidir: cuánto debe,
 * cuánto le queda, quién más puede comprar en su nombre y por qué está
 * bloqueado si lo está.
 *
 * Tres reglas que se ven en el marcado y vienen de decisiones cerradas:
 *
 *  - **El límite bloquea la venta** (P-02): "disponible" no es un consejo, es lo
 *    que el servidor va a dejar facturar.
 *  - **Hasta tres autorizados** (G-10), con cédula obligatoria cada uno.
 *  - **El bloqueo es manual y con motivo** (H8.6): quien lo levanta merece saber
 *    por qué se puso.
 */
const props = defineProps<{ customerId: string, customerName: string }>()

const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const credit = useCredit()
const { can } = useOperator()

const detail = ref<CreditDetail | null>(null)
const statement = ref<Statement | null>(null)
const loading = ref(false)
const error = ref('')

const limitDraft = ref('')
const cutOffDraft = ref(30)
const paymentDraft = ref('')
const paymentComment = ref('')
const blockReason = ref('')

const authorizedName = ref('')
const authorizedNationalId = ref('')
const authorizedPhone = ref('')
const authorizedRelationship = ref('')

const canManage = computed(() => can('credit.block'))
const canPay = computed(() => can('credit.payment'))

const hasAccount = computed(() => detail.value !== null)
const authorized = computed(() => detail.value?.authorized ?? [])
const authorizedFull = computed(() => authorized.value.length >= MAX_AUTHORIZED)

watch(() => props.customerId, load, { immediate: true })

async function load() {
  loading.value = true
  error.value = ''
  statement.value = null

  try {
    detail.value = await credit.detail(props.customerId)
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  } finally {
    loading.value = false
  }
}

async function run(action: () => Promise<unknown>) {
  error.value = ''

  try {
    await action()
    await load()
    emit('changed')
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

const openAccount = () => run(async () => {
  await credit.open(props.customerId, limitDraft.value, cutOffDraft.value)
  limitDraft.value = ''
})

const pay = () => run(async () => {
  await credit.pay(props.customerId, paymentDraft.value, 'cash', paymentComment.value)
  paymentDraft.value = ''
  paymentComment.value = ''
})

const block = () => run(async () => {
  await credit.block(props.customerId, blockReason.value)
  blockReason.value = ''
})

const unblock = () => run(() => credit.unblock(props.customerId))

const addAuthorized = () => run(async () => {
  await credit.addAuthorized(props.customerId, {
    name: authorizedName.value,
    national_id: authorizedNationalId.value,
    phone: authorizedPhone.value,
    relationship: authorizedRelationship.value
  })

  authorizedName.value = ''
  authorizedNationalId.value = ''
  authorizedPhone.value = ''
  authorizedRelationship.value = ''
})

const removeAuthorized = (id: string) => run(() => credit.removeAuthorized(props.customerId, id))

async function showStatement() {
  error.value = ''

  try {
    statement.value = await credit.statement(props.customerId)
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :title="error"
    />

    <p v-if="loading" class="text-muted text-sm">
      {{ t('common.loading') }}
    </p>

    <!-- Sin cuenta: lo único que se puede hacer es abrirla. -->
    <template v-else-if="!hasAccount">
      <p class="text-muted text-sm">
        {{ t('credit.noAccount', { name: props.customerName }) }}
      </p>

      <form v-if="canManage" class="flex flex-wrap items-end gap-2" @submit.prevent="openAccount">
        <UFormField :label="t('credit.limit')" required>
          <UInput v-model="limitDraft" inputmode="decimal" class="w-36" />
        </UFormField>

        <UFormField :label="t('credit.cutOffDay')" :help="t('credit.cutOffDayHint')">
          <UInput
            v-model.number="cutOffDraft"
            type="number"
            min="1"
            max="31"
            class="w-24"
          />
        </UFormField>

        <UButton type="submit" :loading="credit.saving.value" :label="t('credit.openAccount')" />
      </form>
    </template>

    <template v-else>
      <div class="grid grid-cols-3 gap-2 text-sm">
        <div class="rounded-lg border border-default p-2">
          <span class="text-muted block text-xs">{{ t('credit.limit') }}</span>
          <span class="tabular-nums">{{ amount(detail!.account.credit_limit) }}</span>
        </div>
        <div class="rounded-lg border border-default p-2">
          <span class="text-muted block text-xs">{{ t('credit.balance') }}</span>
          <span class="tabular-nums">{{ amount(detail!.account.balance) }}</span>
        </div>
        <div class="rounded-lg border border-default p-2">
          <span class="text-muted block text-xs">{{ t('credit.available') }}</span>
          <span class="tabular-nums font-medium">{{ amount(detail!.available) }}</span>
        </div>
      </div>

      <UAlert
        v-if="detail!.account.is_blocked"
        color="error"
        variant="subtle"
        :title="t('credit.blocked')"
        :description="detail!.account.blocked_reason ?? undefined"
      />

      <!-- Abono en caja, total o parcial. El dinero entra al cajón del turno. -->
      <form v-if="canPay" class="flex flex-wrap items-end gap-2" @submit.prevent="pay">
        <UFormField :label="t('credit.payment')" required>
          <UInput v-model="paymentDraft" inputmode="decimal" class="w-36" />
        </UFormField>

        <UFormField :label="t('credit.comment')" class="grow">
          <UInput v-model="paymentComment" class="w-full" />
        </UFormField>

        <UButton type="submit" :loading="credit.saving.value" :label="t('credit.registerPayment')" />
      </form>

      <div v-if="canManage" class="flex flex-wrap items-end gap-2">
        <template v-if="detail!.account.is_blocked">
          <UButton
            color="neutral"
            variant="subtle"
            :loading="credit.saving.value"
            :label="t('credit.unblock')"
            @click="unblock"
          />
        </template>
        <template v-else>
          <UFormField :label="t('credit.blockReason')" class="grow">
            <UInput v-model="blockReason" class="w-full" />
          </UFormField>
          <UButton
            color="error"
            variant="subtle"
            :disabled="blockReason.trim() === ''"
            :loading="credit.saving.value"
            :label="t('credit.block')"
            @click="block"
          />
        </template>
      </div>

      <div class="flex flex-col gap-2">
        <span class="text-sm font-medium">
          {{ t('credit.authorized', { used: authorized.length, max: MAX_AUTHORIZED }) }}
        </span>

        <ul v-if="authorized.length" class="flex flex-col gap-1 text-sm">
          <li
            v-for="person in authorized"
            :key="person.id"
            class="flex items-center gap-2 rounded-lg border border-default px-2 py-1"
          >
            <span>{{ person.person?.full_name }}</span>
            <span class="text-muted text-xs">{{ person.person?.national_id }}</span>
            <span v-if="person.relationship" class="text-muted text-xs">· {{ person.relationship }}</span>
            <div class="grow" />
            <UButton
              v-if="canManage"
              icon="i-lucide-x"
              color="neutral"
              variant="ghost"
              size="xs"
              :aria-label="t('credit.removeAuthorized')"
              @click="removeAuthorized(person.id)"
            />
          </li>
        </ul>

        <form
          v-if="canManage && !authorizedFull"
          class="flex flex-wrap items-end gap-2"
          @submit.prevent="addAuthorized"
        >
          <UFormField :label="t('credit.authorizedName')" required>
            <UInput v-model="authorizedName" class="w-44" />
          </UFormField>

          <UFormField :label="t('credit.authorizedNationalId')" required :help="t('credit.authorizedNationalIdHint')">
            <UInput v-model="authorizedNationalId" class="w-36" />
          </UFormField>

          <UFormField :label="t('credit.authorizedRelationship')">
            <UInput v-model="authorizedRelationship" class="w-36" />
          </UFormField>

          <UButton type="submit" :loading="credit.saving.value" :label="t('credit.addAuthorized')" />
        </form>

        <p v-else-if="authorizedFull" class="text-muted text-xs">
          {{ t('credit.authorizedFull', { max: MAX_AUTHORIZED }) }}
        </p>
      </div>

      <div class="flex items-center gap-2">
        <UButton
          color="neutral"
          variant="subtle"
          icon="i-lucide-file-text"
          :label="t('credit.statement')"
          @click="showStatement"
        />
        <span class="text-muted text-xs">{{ t('credit.statementHint') }}</span>
      </div>

      <div v-if="statement" class="rounded-lg border border-default p-2 text-sm">
        <p class="text-muted text-xs">
          {{ t('credit.period', { from: statement.period.from, to: statement.period.to }) }}
        </p>

        <div class="mt-1 grid grid-cols-4 gap-2 text-xs">
          <span>{{ t('credit.opening') }}: <b class="tabular-nums">{{ amount(statement.opening_balance) }}</b></span>
          <span>{{ t('credit.charges') }}: <b class="tabular-nums">{{ amount(statement.charges) }}</b></span>
          <span>{{ t('credit.payments') }}: <b class="tabular-nums">{{ amount(statement.payments) }}</b></span>
          <span>{{ t('credit.closing') }}: <b class="tabular-nums">{{ amount(statement.closing_balance) }}</b></span>
        </div>

        <table v-if="statement.entries.length" class="mt-2 w-full text-xs">
          <tbody>
            <tr v-for="(entry, index) in statement.entries" :key="index" class="border-t border-default">
              <td class="py-1">
                {{ entry.date }}
              </td>
              <td class="py-1">
                {{ t(`credit.kind.${entry.kind}`) }}
              </td>
              <td class="text-muted py-1">
                {{ entry.comment }}
              </td>
              <td class="py-1 text-right tabular-nums">
                {{ amount(entry.amount) }}
              </td>
            </tr>
          </tbody>
        </table>

        <p v-else class="text-muted mt-2 text-xs">
          {{ t('credit.noMovements') }}
        </p>
      </div>
    </template>
  </div>
</template>
