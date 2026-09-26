<script setup lang="ts">
import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Category, ProductDraft, TaxCode, Uom } from '../composables/useCatalogAdmin'

/**
 * Alta y edición de un ítem del catálogo.
 *
 * Tres cosas que el formulario sostiene y que vienen de decisiones, no de gusto:
 *
 *  - **El precio lleva el IVA adentro.** El de góndola es lo que paga el
 *    cliente y el motor lo extrae al facturar; la inclusión es propiedad del
 *    código de impuesto (`base = 'gross'`), así que el aviso se arma leyendo el
 *    código elegido en vez de afirmarlo siempre.
 *  - **Exento no es tasa cero.** Dan el mismo total y el libro de ventas los
 *    declara distinto, por eso es una casilla propia y no un impuesto del 0 %.
 *  - **`tracks_stock` distingue producto de servicio** (D-07): un servicio vive
 *    en la misma tabla y no tiene existencias que descontar.
 */
const props = defineProps<{
  categories: Category[]
  uoms: Uom[]
  taxCodes: TaxCode[]
  saving?: boolean
  editing?: boolean
}>()

const emit = defineEmits<{ submit: [], cancel: [] }>()

const draft = defineModel<ProductDraft>({ required: true })

const { t } = useI18n()

const categoryOptions = computed(() => [
  { label: t('products.noCategory'), value: null },
  ...props.categories.map((category) => ({ label: category.name, value: category.id }))
])

// La unidad es obligatoria y aun así abre con un vacío elegible: preseleccionar
// la primera haría que el supervisor guarde "unidad" cuando quería "libra" sin
// haberlo mirado, y eso se paga vendiendo mal el producto.
const uomOptions = computed(() => [
  { label: t('products.chooseUom'), value: null },
  ...props.uoms.map((uom) => ({ label: `${uom.code} · ${uom.name}`, value: uom.id }))
])

const taxOptions = computed(() => [
  { label: t('products.noTaxCode'), value: null },
  ...props.taxCodes.map((tax) => ({ label: `${tax.name} (${tax.rate} %)`, value: tax.id }))
])

/** El código de impuesto elegido decide si el precio se escribe con IVA dentro. */
const selectedTax = computed(() =>
  props.taxCodes.find((tax) => tax.id === draft.value.tax_code_id) ?? null
)

const priceIncludesTax = computed(() => selectedTax.value?.base === 'gross')

// Un servicio no tiene existencias, y sin existencias no hay lotes que vencer.
// Dejar el par en un estado imposible haría que el inventario intentara
// descontar algo que no existe.
watch(
  () => draft.value.tracks_stock,
  (tracks) => {
    if (!tracks) {
      draft.value.tracks_lots = false
      draft.value.allow_negative_stock = false
      draft.value.min_stock = ''
    }
  }
)
</script>

<template>
  <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="emit('submit')">
    <UFormField :label="t('products.sku')" required>
      <UInput
        v-model="draft.sku"
        v-only="'code'"
        autofocus
        class="w-full"
      />
    </UFormField>

    <UFormField :label="t('products.name')" required>
      <UInput v-model="draft.name" class="w-full" />
    </UFormField>

    <UFormField :label="t('products.description')" class="sm:col-span-2">
      <UTextarea v-model="draft.description" :rows="2" class="w-full" />
    </UFormField>

    <UFormField :label="t('products.category')">
      <USelect v-model="draft.category_id" :items="categoryOptions" class="w-full" />
    </UFormField>

    <UFormField :label="t('products.uom')" required :hint="t('products.uomHint')">
      <USelect v-model="draft.uom_id" :items="uomOptions" class="w-full" />
    </UFormField>

    <UFormField :label="t('products.taxCode')">
      <USelect v-model="draft.tax_code_id" :items="taxOptions" class="w-full" />
    </UFormField>

    <UFormField
      :label="t('products.price')"
      required
      :help="priceIncludesTax ? t('products.priceIncludesTax') : t('products.priceExcludesTax')"
    >
      <UInput
        v-model="draft.price"
        v-only="'decimal'"
        type="text"
        inputmode="decimal"
        class="w-full"
      />
    </UFormField>

    <UFormField :label="t('products.cost')" :help="t('products.costHint')">
      <UInput
        v-model="draft.cost"
        v-only="'decimal'"
        type="text"
        inputmode="decimal"
        class="w-full"
      />
    </UFormField>

    <UFormField :label="t('products.minStock')" :class="{ 'opacity-50': !draft.tracks_stock }">
      <UInput
        v-model="draft.min_stock"
        type="text"
        inputmode="decimal"
        :disabled="!draft.tracks_stock"
        class="w-full"
      />
    </UFormField>

    <div class="flex flex-col gap-2 sm:col-span-2">
      <USwitch v-model="draft.tracks_stock" :label="t('products.tracksStock')" :description="t('products.tracksStockHint')" />
      <USwitch
        v-model="draft.tracks_lots"
        :disabled="!draft.tracks_stock"
        :label="t('products.tracksLots')"
        :description="t('products.tracksLotsHint')"
      />
      <USwitch
        v-model="draft.allow_negative_stock"
        :disabled="!draft.tracks_stock"
        :label="t('products.allowNegative')"
        :description="t('products.allowNegativeHint')"
      />
      <USwitch v-model="draft.is_exempt" :label="t('products.exempt')" :description="t('products.exemptHint')" />
      <USwitch v-model="draft.is_active" :label="t('products.active')" :description="t('products.activeHint')" />
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
        :loading="saving"
        :label="editing ? t('products.save') : t('products.create')"
      />
    </div>
  </form>
</template>
