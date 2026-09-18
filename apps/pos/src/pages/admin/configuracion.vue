<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { type PosSettings, type RoundingMode, useSettings } from '../../composables/useSettings'
import { useOperator } from '../../composables/useOperator'

/**
 * Configuración del POS (D-12, D-06, B-09, Q-06, Q-07).
 *
 * Cuatro bloques y ninguno es decorativo:
 *
 *  - **Pie del ticket**: lo único del comprobante que el negocio escribe. El
 *    nombre y el RUC vienen de la licencia y no se editan (Q-08).
 *  - **Redondeo de efectivo**: apagado por defecto. El mecanismo está
 *    construido y probado; encenderlo es decisión del negocio, y cambia lo que
 *    el cliente entrega en mano sin tocar el total del documento.
 *  - **Moneda**: el par córdoba/dólar viene de la instalación; lo que se carga
 *    acá es la **tasa**, que rige hasta que alguien la cambie.
 *  - **Denominaciones**: los billetes y monedas que el arqueo pide contar. Un
 *    arqueo que pide contar billetes inexistentes se abandona a la semana.
 *
 * Lo que **no** se edita está a la vista igual, porque la pregunta "¿por qué no
 * puedo cambiar esto?" merece respuesta en la misma pantalla.
 */
definePage({ meta: { layout: 'admin', permission: 'pos_settings.read' } })

const { t } = useI18n()
const settings = useSettings()
const { can } = useOperator()
const toast = useToast()

const draft = ref<PosSettings | null>(null)
const rateDraft = ref('')
const newValue = ref('')
const newKind = ref<'bill' | 'coin'>('bill')
const newCurrency = ref('')
const includeInactive = ref(false)
const error = ref('')

const canEdit = computed(() => can('pos_settings.update'))
const canSetRate = computed(() => can('pos_exchange_rate.update'))

const roundingOptions = computed(() =>
  (['none', 'nearest', 'up', 'down'] as RoundingMode[]).map((value) => ({
    label: t(`settings.rounding.${value}`),
    value
  }))
)

const kindOptions = computed(() => [
  { label: t('settings.bill'), value: 'bill' },
  { label: t('settings.coin'), value: 'coin' }
])

const currencyOptions = computed(() => {
  const fixed = settings.fixed.value

  return fixed
    ? [
        { label: fixed.base_currency, value: fixed.base_currency },
        { label: fixed.secondary_currency, value: fixed.secondary_currency }
      ]
    : []
})

const roundingOn = computed(() => draft.value?.['cash.rounding_mode'] !== 'none')

const dirty = computed(() => {
  if (!draft.value || !settings.values.value) return false

  return (Object.keys(draft.value) as (keyof PosSettings)[])
    .some((key) => draft.value![key] !== settings.values.value![key])
})

onMounted(async () => {
  try {
    await settings.load()
    draft.value = { ...settings.values.value! }
    newCurrency.value = settings.fixed.value?.base_currency ?? ''

    await Promise.all([
      settings.loadDenominations(includeInactive.value),
      settings.loadRate(settings.fixed.value?.secondary_currency ?? 'USD')
    ])

    rateDraft.value = settings.rate.value?.rate ?? ''
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
})

watch(includeInactive, async (value) => {
  await settings.loadDenominations(value).catch(() => undefined)
})

async function save() {
  if (!draft.value) return

  error.value = ''

  try {
    await settings.save(draft.value)
    draft.value = { ...settings.values.value! }
    toast.add({ title: t('settings.saved'), color: 'success' })
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

async function saveRate() {
  error.value = ''

  try {
    await settings.saveRate(settings.fixed.value?.secondary_currency ?? 'USD', rateDraft.value)
    toast.add({ title: t('settings.rateSaved'), color: 'success' })
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

async function addDenomination() {
  error.value = ''

  try {
    await settings.addDenomination({
      currency_code: newCurrency.value,
      value: newValue.value.trim(),
      kind: newKind.value
    })

    newValue.value = ''
    await settings.loadDenominations(includeInactive.value)
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

async function toggleDenomination(id: string, active: boolean) {
  error.value = ''

  try {
    if (active) await settings.removeDenomination(id)
    else await settings.restoreDenomination(id)

    await settings.loadDenominations(includeInactive.value)
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}
</script>

<template>
  <div v-if="draft && settings.fixed.value" class="flex flex-col gap-4">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :title="error"
    />

    <!-- Pie del ticket -->
    <section class="flex flex-col gap-3 rounded-lg border border-default p-3">
      <h2 class="font-medium">
        {{ t('settings.receiptSection') }}
      </h2>

      <UFormField :label="t('settings.receiptFooter')" :help="t('settings.receiptFooterHint')">
        <UTextarea
          v-model="draft['receipt.footer']"
          :rows="2"
          :disabled="!canEdit"
          class="w-full"
        />
      </UFormField>

      <UFormField :label="t('settings.receiptNotice')" :help="t('settings.receiptNoticeHint')">
        <UTextarea
          v-model="draft['receipt.notice']"
          :rows="2"
          :disabled="!canEdit"
          class="w-full"
        />
      </UFormField>

      <!-- El nombre y el RUC vienen de la licencia firmada y no son editables. -->
      <p class="text-muted text-xs">
        {{ t('settings.brandingFixed') }}
      </p>
    </section>

    <!-- Redondeo de efectivo -->
    <section class="flex flex-col gap-3 rounded-lg border border-default p-3">
      <h2 class="font-medium">
        {{ t('settings.cashSection') }}
      </h2>

      <div class="flex flex-wrap items-end gap-2">
        <UFormField :label="t('settings.roundingMode')">
          <USelect
            v-model="draft['cash.rounding_mode']"
            :items="roundingOptions"
            :disabled="!canEdit"
            class="w-56"
          />
        </UFormField>

        <UFormField
          :label="t('settings.roundingIncrement')"
          :help="t('settings.roundingIncrementHint')"
        >
          <UInput
            v-model="draft['cash.rounding_increment']"
            inputmode="decimal"
            :disabled="!canEdit || !roundingOn"
            class="w-32"
          />
        </UFormField>
      </div>

      <!-- El redondeo es del cobro, no del documento: el total fiscal no se
           toca, o el libro de ventas declararía una cifra que ninguna línea
           justifica. -->
      <p class="text-muted text-xs">
        {{ roundingOn ? t('settings.roundingOnHint') : t('settings.roundingOffHint') }}
      </p>
    </section>

    <!-- Impuestos y modo degradado -->
    <section class="flex flex-col gap-3 rounded-lg border border-default p-3">
      <h2 class="font-medium">
        {{ t('settings.operationSection') }}
      </h2>

      <USwitch
        v-model="draft['tax.fixed_quota_regime']"
        :disabled="!canEdit"
        :label="t('settings.fixedQuota')"
        :description="t('settings.fixedQuotaHint')"
      />

      <UFormField :label="t('settings.offlineMaxHours')" :help="t('settings.offlineMaxHoursHint')">
        <UInput
          v-model.number="draft['pos.offline_max_hours']"
          type="number"
          min="1"
          max="720"
          :disabled="!canEdit"
          class="w-32"
        />
      </UFormField>
    </section>

    <!-- Propina (G-16). Apagada por defecto: en retail el renglón solo estorba. -->
    <section class="flex flex-col gap-3 rounded-lg border border-default p-3">
      <h2 class="font-medium">
        {{ t('settings.tipSection') }}
      </h2>

      <USwitch
        v-model="draft['tip.enabled']"
        :disabled="!canEdit"
        :label="t('settings.tipEnabled')"
        :description="t('settings.tipEnabledHint')"
      />

      <UFormField
        v-if="draft['tip.enabled']"
        :label="t('settings.tipSuggested')"
        :help="t('settings.tipSuggestedHint')"
      >
        <UInput
          v-model="draft['tip.suggested_percent']"
          type="number"
          min="0"
          max="100"
          step="0.5"
          :disabled="!canEdit"
          class="w-32"
        />
      </UFormField>

      <p class="text-muted text-sm">
        {{ t('settings.tipNotInvoiced') }}
      </p>
    </section>

    <div v-if="canEdit" class="flex justify-end">
      <UButton
        icon="i-lucide-save"
        :disabled="!dirty"
        :loading="settings.saving.value"
        :label="t('settings.save')"
        @click="save"
      />
    </div>

    <!-- Moneda -->
    <section class="flex flex-col gap-3 rounded-lg border border-default p-3">
      <h2 class="font-medium">
        {{ t('settings.currencySection') }}
      </h2>

      <p class="text-muted text-sm">
        {{ t('settings.currencyPair', {
          base: settings.fixed.value.base_currency,
          secondary: settings.fixed.value.secondary_currency
        }) }}
      </p>

      <div class="flex flex-wrap items-end gap-2">
        <UFormField
          :label="t('settings.rate', { currency: settings.fixed.value.secondary_currency })"
          :help="t('settings.rateHint')"
        >
          <UInput
            v-model="rateDraft"
            inputmode="decimal"
            :disabled="!canSetRate"
            class="w-40"
          />
        </UFormField>

        <UButton
          v-if="canSetRate"
          :loading="settings.saving.value"
          :disabled="rateDraft.trim() === ''"
          :label="t('settings.saveRate')"
          @click="saveRate"
        />
      </div>

      <p v-if="!settings.rate.value" class="text-warning text-xs">
        {{ t('settings.noRate') }}
      </p>
    </section>

    <!-- Denominaciones -->
    <section class="flex flex-col gap-3 rounded-lg border border-default p-3">
      <div class="flex items-center gap-2">
        <h2 class="font-medium">
          {{ t('settings.denominationsSection') }}
        </h2>
        <div class="grow" />
        <USwitch v-model="includeInactive" :label="t('settings.includeInactive')" />
      </div>

      <p class="text-muted text-xs">
        {{ t('settings.denominationsHint') }}
      </p>

      <div class="flex flex-wrap gap-2">
        <UBadge
          v-for="denomination in settings.denominations.value"
          :key="denomination.id"
          :color="denomination.is_active ? 'neutral' : 'error'"
          variant="subtle"
          class="gap-1"
        >
          {{ denomination.currency_code }} {{ denomination.value }}
          <span class="text-xs">{{ t(`settings.${denomination.kind}`) }}</span>
          <UButton
            v-if="canEdit"
            :icon="denomination.is_active ? 'i-lucide-x' : 'i-lucide-rotate-ccw'"
            color="neutral"
            variant="ghost"
            size="xs"
            :aria-label="denomination.is_active ? t('settings.removeDenomination') : t('settings.restoreDenomination')"
            @click="toggleDenomination(denomination.id, denomination.is_active)"
          />
        </UBadge>
      </div>

      <div v-if="canEdit" class="flex flex-wrap items-end gap-2">
        <UFormField :label="t('settings.currency')">
          <USelect v-model="newCurrency" :items="currencyOptions" class="w-28" />
        </UFormField>

        <UFormField :label="t('settings.value')">
          <UInput v-model="newValue" inputmode="decimal" class="w-28" />
        </UFormField>

        <UFormField :label="t('settings.kind')">
          <USelect v-model="newKind" :items="kindOptions" class="w-32" />
        </UFormField>

        <UButton
          icon="i-lucide-plus"
          color="neutral"
          variant="subtle"
          :disabled="newValue.trim() === ''"
          :loading="settings.saving.value"
          :label="t('settings.addDenomination')"
          @click="addDenomination"
        />
      </div>
    </section>

    <!-- Lo que viene de la instalación. Se muestra porque "¿por qué no puedo
         cambiar esto?" merece respuesta en la misma pantalla. -->
    <section class="flex flex-col gap-2 rounded-lg border border-default p-3">
      <h2 class="font-medium">
        {{ t('settings.fixedSection') }}
      </h2>

      <div class="grid gap-2 text-sm sm:grid-cols-3">
        <div>
          <span class="text-muted block text-xs">{{ t('settings.businessProfile') }}</span>
          {{ settings.fixed.value.business_profile }}
        </div>
        <div>
          <span class="text-muted block text-xs">{{ t('settings.costingMethod') }}</span>
          {{ settings.fixed.value.costing_method }}
        </div>
        <div>
          <span class="text-muted block text-xs">{{ t('settings.tempItemLimit') }}</span>
          {{ settings.fixed.value.temporary_item_daily_limit }}
        </div>
        <div>
          <span class="text-muted block text-xs">{{ t('settings.requireShift') }}</span>
          {{ settings.fixed.value.require_shift ? t('common.yes') : t('common.no') }}
        </div>
        <div>
          <span class="text-muted block text-xs">{{ t('settings.refundRequiresOriginal') }}</span>
          {{ settings.fixed.value.refund_requires_original ? t('common.yes') : t('common.no') }}
        </div>
      </div>

      <p class="text-muted text-xs">
        {{ t('settings.fixedHint') }}
      </p>
    </section>
  </div>
</template>
