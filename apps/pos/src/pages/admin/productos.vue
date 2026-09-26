<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import {
  type AdminProduct,
  type ProductDraft,
  draftOf,
  emptyDraft,
  useCatalogAdmin
} from '../../composables/useCatalogAdmin'
import { useAvailability } from '../../composables/useAvailability'
import { useCatalog } from '../../composables/useCatalog'
import { useOperator } from '../../composables/useOperator'
import { amount } from '../../utils/money'
import ProductForm from '../../components/ProductForm.vue'

/**
 * Mantenimiento del catálogo (H1).
 *
 * Es la trastienda de lo que la caja consume cacheado. Por eso, al guardar, se
 * vuelve a bajar el catálogo del terminal: sin ese paso el supervisor corrige
 * un precio, vuelve a la caja y sigue cobrando el viejo hasta el siguiente
 * sondeo — con el cliente delante.
 *
 * Los productos dados de baja se ven **solo si se piden**: la baja es lógica
 * porque el histórico inmutable los referencia (P1), pero mostrarlos siempre
 * convertiría la lista en un archivo muerto.
 */
definePage({ meta: { layout: 'admin', permission: 'catalog.product.read' } })

const { t } = useI18n()
const admin = useCatalogAdmin()
const catalog = useCatalog()
const availability = useAvailability()
const { can } = useOperator()
const toast = useToast()

/**
 * La lista 86 (F1-B): lo que se acabó hoy.
 *
 * Vive acá y no en el formulario del producto porque **no es editar el
 * catálogo**: es decir que hoy no hay. Se marca de un clic, se repone sola al
 * abrir el turno siguiente, y el histórico y los reportes ni se enteran.
 *
 * Desactivar el producto para lo mismo es lo que hay que evitar: eso lo saca de
 * reportes e importaciones, y alguien tiene que acordarse de reactivarlo.
 */
const canMark = computed(() => can('catalog.availability'))

async function toggleAvailability(product: AdminProduct) {
  try {
    if (availability.isUnavailable(product.id)) {
      await availability.restore(product.id)
      toast.add({ title: t('products.backInStock', { name: product.name }), color: 'success' })
    } else {
      await availability.mark(product.id)
      toast.add({ title: t('products.markedSoldOut', { name: product.name }), color: 'warning' })
    }
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

const search = ref('')
const categoryId = ref<string | null>(null)
const includeInactive = ref(false)

const open = ref(false)
const editingId = ref<string | null>(null)
const draft = ref<ProductDraft>(emptyDraft())
const formError = ref('')

// Con ERP, el catálogo es del ERP: el permiso alcanza pero el dato no es
// nuestro, y ofrecer guardar sería prometer algo que el servidor rechaza.
const canCreate = computed(() => can('catalog.product.create') && !admin.erpOwned.value)
const canUpdate = computed(() => can('catalog.product.update') && !admin.erpOwned.value)
const canDeactivate = computed(() => can('catalog.product.delete') && !admin.erpOwned.value)

const categoryOptions = computed(() => [
  { label: t('products.allCategories'), value: null },
  ...admin.categories.value.map((category) => ({ label: category.name, value: category.id }))
])

onMounted(async () => {
  await Promise.all([admin.loadReferences(), admin.checkOwnership(), availability.refresh()])
  await refresh()
})

// El filtro lo resuelve el servidor. Un temporizador evita una consulta por
// tecla —el error que la caja evita con la búsqueda local (D-04)— sin renunciar
// a ver también lo dado de baja, que la caché no guarda.
let debounce: ReturnType<typeof setTimeout> | undefined

watch([search, categoryId, includeInactive], () => {
  clearTimeout(debounce)
  debounce = setTimeout(refresh, 250)
})

async function refresh() {
  try {
    await admin.list({
      search: search.value.trim(),
      categoryId: categoryId.value,
      includeInactive: includeInactive.value
    })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

function startCreate() {
  editingId.value = null
  formError.value = ''
  draft.value = emptyDraft()
  open.value = true
}

async function startEdit(product: AdminProduct) {
  formError.value = ''

  try {
    // Se relee la ficha completa: el listado trae lo justo para la tabla y
    // editar sobre datos parciales borraría lo que no viajó.
    const full = await admin.find(product.id)
    editingId.value = full.id
    draft.value = draftOf(full)
    open.value = true
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function submit() {
  formError.value = ''

  try {
    if (editingId.value) {
      await admin.update(editingId.value, draft.value)
      toast.add({ title: t('products.updated'), color: 'success' })
    } else {
      await admin.create(draft.value)
      toast.add({ title: t('products.created'), color: 'success' })
    }

    open.value = false
    await Promise.all([refresh(), catalog.sync()])
  } catch (err) {
    formError.value = firstApiErrorMessage(err)
  }
}

async function deactivate(product: AdminProduct) {
  try {
    await admin.deactivate(product.id)
    toast.add({ title: t('products.deactivated', { name: product.name }), color: 'success' })
    await Promise.all([refresh(), catalog.sync()])
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <!-- Quien venga a corregir un precio tiene que saber dónde se corrige, no
         descubrirlo cuando el botón no aparece. -->
    <UAlert
      v-if="admin.erpOwned.value"
      color="info"
      variant="subtle"
      icon="i-lucide-lock"
      :title="t('products.ownedByErp')"
      :description="t('products.ownedByErpHint')"
    />

    <div class="flex flex-wrap items-end gap-2">
      <UFormField :label="t('products.search')" class="grow">
        <UInput
          v-model="search"
          icon="i-lucide-search"
          :placeholder="t('products.searchPlaceholder')"
          class="w-full"
        />
      </UFormField>

      <UFormField :label="t('products.category')">
        <USelect v-model="categoryId" :items="categoryOptions" class="w-56" />
      </UFormField>

      <USwitch v-model="includeInactive" :label="t('products.includeInactive')" class="pb-2" />

      <UButton
        v-if="canCreate"
        icon="i-lucide-plus"
        :label="t('products.new')"
        class="ml-auto"
        @click="startCreate"
      />
    </div>

    <div
      v-if="!admin.loading.value && admin.products.value.length === 0"
      class="text-muted flex flex-col items-center gap-1 rounded-lg border border-default p-10 text-center"
    >
      <UIcon name="i-lucide-package-search" class="size-8" />
      <p class="font-medium">
        {{ t('products.empty') }}
      </p>
      <p class="text-sm">
        {{ t('products.emptyHint') }}
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
            <th class="px-2 py-1 font-medium">
              {{ t('products.category') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('products.price') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('products.status') }}
            </th>
            <th class="w-24" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="product in admin.products.value"
            :key="product.id"
            class="border-t border-default hover:bg-elevated/60"
            :class="{ 'opacity-60': !product.is_active }"
          >
            <td class="px-2 py-1 font-mono text-xs">
              {{ product.sku }}
            </td>
            <td class="px-2 py-1">
              {{ product.name }}
            </td>
            <td class="text-muted px-2 py-1">
              {{ product.category?.name ?? '—' }}
            </td>
            <td class="px-2 py-1 text-right tabular-nums">
              {{ amount(product.price) }}
            </td>
            <td class="px-2 py-1">
              <!-- Agotado gana sobre lo demás: es lo que cambia hoy. -->
              <UBadge v-if="availability.isUnavailable(product.id)" color="warning" variant="subtle">
                {{ t('products.soldOut') }}
              </UBadge>
              <UBadge v-else-if="!product.is_active" color="neutral" variant="subtle">
                {{ t('products.inactive') }}
              </UBadge>
              <UBadge v-else-if="product.is_exempt" color="info" variant="subtle">
                {{ t('products.exempt') }}
              </UBadge>
              <UBadge v-else-if="!product.tracks_stock" color="neutral" variant="subtle">
                {{ t('products.service') }}
              </UBadge>
            </td>
            <td class="px-2 py-1 text-right">
              <UButton
                v-if="canUpdate"
                icon="i-lucide-pencil"
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="t('products.edit')"
                @click="startEdit(product)"
              />
              <!-- Se acabó hoy, no se da de baja: el producto sigue en el
                   catálogo y vuelve solo al abrir el turno siguiente. -->
              <UButton
                v-if="canMark && product.is_active"
                :icon="availability.isUnavailable(product.id) ? 'i-lucide-package-check' : 'i-lucide-package-x'"
                :color="availability.isUnavailable(product.id) ? 'success' : 'neutral'"
                variant="ghost"
                size="xs"
                :loading="availability.saving.value"
                :aria-label="availability.isUnavailable(product.id) ? t('products.markAvailable') : t('products.markSoldOut')"
                @click="toggleAvailability(product)"
              />
              <UButton
                v-if="canDeactivate && product.is_active"
                icon="i-lucide-archive"
                color="neutral"
                variant="ghost"
                size="xs"
                :aria-label="t('products.deactivate')"
                @click="deactivate(product)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UModal v-model:open="open" :title="editingId ? t('products.edit') : t('products.new')">
      <template #body>
        <div class="flex flex-col gap-3">
          <UAlert
            v-if="formError"
            color="error"
            variant="subtle"
            :title="formError"
          />

          <ProductForm
            v-model="draft"
            :categories="admin.categories.value"
            :uoms="admin.uoms.value"
            :tax-codes="admin.taxCodes.value"
            :saving="admin.saving.value"
            :editing="editingId !== null"
            @submit="submit"
            @cancel="open = false"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>
