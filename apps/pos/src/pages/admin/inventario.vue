<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { type StockRow, useInventory } from '../../composables/useInventory'
import { useCatalogAdmin } from '../../composables/useCatalogAdmin'
import { useOperator } from '../../composables/useOperator'
import { quantity } from '../../utils/money'

/**
 * Existencias (B-03, H1.3).
 *
 * **El stock no se edita.** Se le suman movimientos: una entrada con su costo o
 * un ajuste con su motivo, y el saldo es la suma. Por eso esta pantalla no tiene
 * una celda editable con la cantidad — tenerla haría del inventario un número
 * que nadie puede explicar.
 *
 * Los lotes vencidos se muestran arriba como **alerta**, no como un reporte que
 * alguien tenga que acordarse de abrir: es lo único del inventario que produce
 * acción operativa inmediata.
 */
definePage({ meta: { layout: 'admin', permission: 'inventory.read' } })

const { t } = useI18n()
const inventory = useInventory()
const catalog = useCatalogAdmin()
const { can } = useOperator()
const toast = useToast()

// Cadena vacía y no `null`: siempre hay una ubicación elegida salvo en el
// instante anterior a que carguen, y un selector con opción nula sugeriría que
// se puede mover inventario sin decir dónde.
const locationId = ref('')
const search = ref('')
const belowMin = ref(false)

const movingFor = ref<StockRow | null>(null)
const mode = ref<'receipt' | 'adjustment'>('receipt')
const qty = ref('')
const unitCost = ref('')
const comment = ref('')
const formError = ref('')

const canReceive = computed(() => can('inventory.receive'))
const canAdjust = computed(() => can('inventory.adjust'))

const locationOptions = computed(() =>
  inventory.locations.value.map((location) => ({ label: location.name, value: location.id }))
)

onMounted(async () => {
  try {
    await inventory.loadLocations()
    locationId.value = inventory.locations.value[0]?.id ?? ''
    await refresh()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
})

let debounce: ReturnType<typeof setTimeout> | undefined

watch([search, belowMin], () => {
  clearTimeout(debounce)
  debounce = setTimeout(refresh, 250)
})

watch(locationId, refresh)

async function refresh() {
  if (!locationId.value) return

  try {
    await Promise.all([
      inventory.balances(locationId.value, { search: search.value.trim(), belowMin: belowMin.value }),
      inventory.loadExpiredLots(locationId.value).catch(() => undefined)
    ])
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

function startMovement(row: StockRow, kind: 'receipt' | 'adjustment') {
  movingFor.value = row
  mode.value = kind
  qty.value = ''
  unitCost.value = ''
  comment.value = ''
  formError.value = ''
}

async function submit() {
  if (!movingFor.value || !locationId.value) return

  formError.value = ''

  try {
    if (mode.value === 'receipt') {
      await inventory.receive({
        productId: movingFor.value.product_id,
        locationId: locationId.value,
        qty: qty.value,
        unitCost: unitCost.value,
        comment: comment.value
      })
    } else {
      await inventory.adjust({
        productId: movingFor.value.product_id,
        locationId: locationId.value,
        qty: qty.value,
        comment: comment.value
      })
    }

    toast.add({ title: t('inventory.recorded'), color: 'success' })
    movingFor.value = null
    await refresh()
    // El catálogo del terminal no cambia con un movimiento, pero el precio de
    // costo sí alimenta la valuación: se relee para no dejar la trastienda
    // mostrando el costo anterior.
    await catalog.loadReferences(true).catch(() => undefined)
  } catch (err) {
    formError.value = firstApiErrorMessage(err)
  }
}

function isBelowMin(row: StockRow): boolean {
  return row.min_stock !== null && Number.parseFloat(row.qty) < Number.parseFloat(row.min_stock)
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-end gap-2">
      <UFormField :label="t('inventory.location')">
        <USelect v-model="locationId" :items="locationOptions" class="w-56" />
      </UFormField>

      <UFormField :label="t('inventory.search')" class="grow">
        <UInput
          v-model="search"
          icon="i-lucide-search"
          :placeholder="t('inventory.searchPlaceholder')"
          class="w-full"
        />
      </UFormField>

      <USwitch v-model="belowMin" :label="t('inventory.belowMin')" class="pb-2" />
    </div>

    <!-- Vencidos primero: es lo único que hay que sacar de la góndola hoy. -->
    <UAlert
      v-if="inventory.expired.value.length > 0"
      color="warning"
      variant="subtle"
      icon="i-lucide-alert-triangle"
      :title="t('inventory.expiredLots', { count: inventory.expired.value.length })"
      :description="inventory.expired.value.map((lot) => `${lot.product?.name} · ${lot.code} (${lot.expires_on})`).join(' · ')"
    />

    <div
      v-if="!inventory.loading.value && inventory.rows.value.length === 0"
      class="text-muted flex flex-col items-center gap-1 rounded-lg border border-default p-10 text-center"
    >
      <UIcon name="i-lucide-boxes" class="size-8" />
      <p class="font-medium">
        {{ t('inventory.empty') }}
      </p>
      <p class="text-sm">
        {{ t('inventory.emptyHint') }}
      </p>
    </div>

    <div v-else class="overflow-x-auto rounded-lg border border-default">
      <table class="w-full text-sm">
        <thead class="bg-elevated text-muted">
          <tr class="text-left">
            <th class="px-2 py-1 font-medium">
              {{ t('products.sku') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('products.name') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('inventory.qty') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('inventory.minStock') }}
            </th>
            <th class="w-24" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in inventory.rows.value"
            :key="row.product_id"
            class="border-t border-default hover:bg-elevated/60"
          >
            <td class="px-2 py-1 font-mono text-xs">
              {{ row.sku }}
            </td>
            <td class="px-2 py-1">
              {{ row.name }}
            </td>
            <td
              class="px-2 py-1 text-right font-medium tabular-nums"
              :class="{ 'text-error': isBelowMin(row) }"
            >
              {{ quantity(row.qty) }} <span class="text-muted text-xs">{{ row.uom_code }}</span>
            </td>
            <td class="text-muted px-2 py-1 text-right tabular-nums">
              {{ row.min_stock ? quantity(row.min_stock) : '—' }}
            </td>
            <td class="px-2 py-1 text-right">
              <UButton
                v-if="canReceive"
                icon="i-lucide-package-plus"
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="t('inventory.receive')"
                @click="startMovement(row, 'receipt')"
              />
              <UButton
                v-if="canAdjust"
                icon="i-lucide-scale"
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="t('inventory.adjust')"
                @click="startMovement(row, 'adjustment')"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UModal
      :open="movingFor !== null"
      :title="mode === 'receipt' ? t('inventory.receive') : t('inventory.adjust')"
      @update:open="(value: boolean) => { if (!value) movingFor = null }"
    >
      <template #body>
        <form class="flex flex-col gap-3" @submit.prevent="submit">
          <UAlert
            v-if="formError"
            color="error"
            variant="subtle"
            :title="formError"
          />

          <p class="text-muted text-sm">
            {{ movingFor?.name }} · {{ t('inventory.current', { qty: quantity(movingFor?.qty ?? '0') }) }}
          </p>

          <UFormField
            :label="t('inventory.qty')"
            required
            :help="mode === 'adjustment' ? t('inventory.adjustQtyHint') : t('inventory.receiveQtyHint')"
          >
            <UInput
              v-model="qty"
              inputmode="decimal"
              autofocus
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-if="mode === 'receipt'"
            :label="t('inventory.unitCost')"
            required
            :help="t('inventory.unitCostHint')"
          >
            <UInput v-model="unitCost" inputmode="decimal" class="w-full" />
          </UFormField>

          <UFormField
            :label="t('inventory.comment')"
            :required="mode === 'adjustment'"
            :help="mode === 'adjustment' ? t('inventory.adjustCommentHint') : undefined"
          >
            <UInput v-model="comment" class="w-full" />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              type="button"
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="movingFor = null"
            />
            <UButton
              type="submit"
              :loading="inventory.saving.value"
              :disabled="qty.trim() === '' || (mode === 'adjustment' && comment.trim() === '')"
              :label="t('inventory.record')"
            />
          </div>
        </form>
      </template>
    </UModal>
  </div>
</template>
