<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CustomerDraft } from '../composables/useCustomers'

/**
 * Ficha de cliente (P-03, D-09, G-10).
 *
 * La clase de cliente decide qué se pide: el de **efectivo** paga y se va —el
 * nombre alcanza—, el de **cuenta** abre crédito y ahí la cédula es
 * obligatoria, porque la cuenta es personal y sin cédula no se sabe de quién
 * es. Pedir cédula a todos ahuyentaría al que compra una gaseosa; no pedirla al
 * de cuenta dejaría el crédito sin dueño.
 *
 * **Exonerado no es lo mismo que exento**, y no abarata la compra: con el IVA
 * incluido en el precio, exonerar mueve el importe de base gravada a exento y
 * el cliente paga igual. Por eso la referencia de la exoneración es un campo, no
 * un detalle: es lo que se declara.
 */
const props = defineProps<{ saving?: boolean, editing?: boolean }>()

const emit = defineEmits<{ submit: [], cancel: [] }>()

const draft = defineModel<CustomerDraft>({ required: true })

const { t } = useI18n()

const kindOptions = computed(() => [
  { label: t('customers.kindCash'), value: 'cash' },
  { label: t('customers.kindAccount'), value: 'account' }
])

const needsNationalId = computed(() => draft.value.kind === 'account')
</script>

<template>
  <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="emit('submit')">
    <UFormField :label="t('customers.name')" required>
      <UInput v-model="draft.name" autofocus class="w-full" />
    </UFormField>

    <UFormField :label="t('customers.kind')" :help="needsNationalId ? t('customers.kindAccountHint') : t('customers.kindCashHint')">
      <USelect v-model="draft.kind" :items="kindOptions" class="w-full" />
    </UFormField>

    <UFormField
      :label="t('customers.nationalId')"
      :required="needsNationalId"
      :help="needsNationalId ? t('customers.nationalIdHint') : undefined"
    >
      <UInput v-model="draft.national_id" class="w-full" />
    </UFormField>

    <UFormField :label="t('customers.taxId')">
      <UInput v-model="draft.tax_id" class="w-full" />
    </UFormField>

    <UFormField :label="t('customers.phone')">
      <UInput v-model="draft.phone" class="w-full" />
    </UFormField>

    <UFormField :label="t('customers.whatsapp')" :help="t('customers.whatsappHint')">
      <UInput v-model="draft.whatsapp" class="w-full" />
    </UFormField>

    <UFormField :label="t('customers.email')">
      <UInput v-model="draft.email" type="email" class="w-full" />
    </UFormField>

    <UFormField :label="t('customers.code')" :help="t('customers.codeHint')">
      <UInput v-model="draft.code" class="w-full" />
    </UFormField>

    <UFormField :label="t('customers.address')" class="sm:col-span-2">
      <UTextarea v-model="draft.address" :rows="2" class="w-full" />
    </UFormField>

    <UFormField :label="t('customers.discountPercent')" :help="t('customers.discountPercentHint')">
      <UInput v-model="draft.discount_percent" inputmode="decimal" class="w-full" />
    </UFormField>

    <UFormField
      v-if="draft.is_tax_exempt"
      :label="t('customers.exemptReference')"
      :help="t('customers.exemptReferenceHint')"
    >
      <UInput v-model="draft.tax_exempt_reference" class="w-full" />
    </UFormField>

    <div class="flex flex-col gap-2 sm:col-span-2">
      <USwitch
        v-model="draft.is_tax_exempt"
        :label="t('customers.exempt')"
        :description="t('customers.exemptHint')"
      />
      <USwitch
        v-model="draft.consent_whatsapp"
        :label="t('customers.consentWhatsapp')"
        :description="t('customers.consentHint')"
      />
      <USwitch v-model="draft.consent_email" :label="t('customers.consentEmail')" />
      <USwitch v-model="draft.is_active" :label="t('customers.active')" :description="t('customers.activeHint')" />
    </div>

    <div class="flex justify-end gap-2 sm:col-span-2">
      <UButton
        type="button"
        color="neutral"
        variant="ghost"
        :label="t('common.cancel')"
        @click="emit('cancel')"
      />
      <UButton
        type="submit"
        :loading="props.saving"
        :label="props.editing ? t('customers.save') : t('customers.create')"
      />
    </div>
  </form>
</template>
