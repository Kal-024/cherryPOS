<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { TOTAL_ROWS, type TemplateBlock } from '../composables/useReceiptTemplates'

/**
 * Un bloque de la plantilla (D-11).
 *
 * El editor muestra **solo los controles que el bloque entiende**: un separador
 * no tiene alineación y un espaciador no tiene texto. Ofrecer todo a todos
 * convertiría el editor en las veinte banderas de OSPOS que la decisión quiso
 * evitar, ahora repartidas por bloque.
 *
 * Los `{{marcadores}}` se escriben a mano a propósito: son pocos, la vista
 * previa los resuelve al instante y un selector de campos sería una capa más
 * que mantener sincronizada con el renderizador del servidor.
 */
const block = defineModel<TemplateBlock>({ required: true })

const props = defineProps<{ index: number, count: number }>()

const emit = defineEmits<{ move: [number], remove: [] }>()

const { t } = useI18n()

const alignOptions = computed(() =>
  (['left', 'center', 'right'] as const).map((value) => ({ label: t(`receipts.align.${value}`), value }))
)

/** Compacta ahorra papel; detallada deja impreso el precio unitario. */
const layoutOptions = computed(() => [
  { label: t('receipts.layoutCompact'), value: 'compact' },
  { label: t('receipts.layoutDetailed'), value: 'detailed' }
])

const sizeOptions = computed(() => [
  { label: t('receipts.size.md'), value: 'md' },
  { label: t('receipts.size.sm'), value: 'sm' }
])

const totalRows = TOTAL_ROWS

function toggleRow(row: string, checked: boolean) {
  const current = new Set(block.value.show ?? [])

  if (checked) current.add(row)
  else current.delete(row)

  // Se reordena según `TOTAL_ROWS` y no según el orden de clic: el ticket se
  // lee de arriba abajo y el total siempre va al final.
  block.value = { ...block.value, show: totalRows.filter((item) => current.has(item)) }
}

function addField() {
  block.value = { ...block.value, fields: [...(block.value.fields ?? []), { label: '', value: '' }] }
}

function removeField(index: number) {
  block.value = {
    ...block.value,
    fields: (block.value.fields ?? []).filter((_, position) => position !== index)
  }
}
</script>

<template>
  <div class="flex flex-col gap-2 rounded-lg border border-default p-2">
    <div class="flex items-center gap-2">
      <UBadge color="neutral" variant="subtle">
        {{ t(`receipts.block.${block.type}`) }}
      </UBadge>

      <div class="grow" />

      <UButton
        icon="i-lucide-arrow-up"
        color="neutral"
        variant="ghost"
        size="xs"
        :disabled="props.index === 0"
        :aria-label="t('receipts.moveUp')"
        @click="emit('move', -1)"
      />
      <UButton
        icon="i-lucide-arrow-down"
        color="neutral"
        variant="ghost"
        size="xs"
        :disabled="props.index === props.count - 1"
        :aria-label="t('receipts.moveDown')"
        @click="emit('move', 1)"
      />
      <UButton
        icon="i-lucide-trash-2"
        color="neutral"
        variant="ghost"
        size="xs"
        :aria-label="t('receipts.removeBlock')"
        @click="emit('remove')"
      />
    </div>

    <template v-if="block.type === 'text'">
      <UFormField :label="t('receipts.text')" :help="t('receipts.textHint')">
        <UInput v-model="block.content" class="w-full" />
      </UFormField>

      <div class="flex flex-wrap items-end gap-2">
        <UFormField :label="t('receipts.alignLabel')">
          <USelect v-model="block.align" :items="alignOptions" class="w-32" />
        </UFormField>

        <UFormField :label="t('receipts.sizeLabel')">
          <USelect v-model="block.size" :items="sizeOptions" class="w-32" />
        </UFormField>

        <USwitch v-model="block.bold" :label="t('receipts.bold')" class="pb-2" />
      </div>
    </template>

    <template v-else-if="block.type === 'spacer'">
      <UFormField :label="t('receipts.lines')" :help="t('receipts.linesHint')">
        <UInput
          v-model.number="block.lines"
          type="number"
          min="1"
          max="10"
          class="w-24"
        />
      </UFormField>
    </template>

    <template v-else-if="block.type === 'items'">
      <div class="flex flex-col gap-3">
        <!-- Una línea por producto o dos. En un rollo, cada producto que ocupa
             dos renglones es el doble de papel en cada ticket del día. -->
        <UFormField :label="t('receipts.itemsLayout')" :hint="t('receipts.itemsLayoutHint')">
          <USelect
            :model-value="block.layout ?? 'compact'"
            :items="layoutOptions"
            class="w-64"
            @update:model-value="block = { ...block, layout: $event as 'compact' | 'detailed' }"
          />
        </UFormField>

        <div class="flex flex-wrap gap-4">
          <USwitch
            v-if="(block.layout ?? 'compact') === 'detailed'"
            v-model="block.show_unit_price"
            :label="t('receipts.showUnitPrice')"
          />
          <!-- Una raya sola no dice qué es cada número: sin rótulos, el cliente
               no sabe cuál columna es la cantidad y cuál el importe. -->
          <USwitch
            v-if="(block.layout ?? 'compact') === 'compact'"
            :model-value="block.show_header ?? true"
            :label="t('receipts.showHeader')"
            @update:model-value="block = { ...block, show_header: $event }"
          />
          <USwitch v-model="block.show_discount" :label="t('receipts.showDiscount')" />
        </div>
      </div>
    </template>

    <template v-else-if="block.type === 'payments'">
      <!-- El vuelto se asienta como pago negativo en efectivo: ocultarlo dejaría
           el ticket sin explicar la plata que volvió al cliente. -->
      <USwitch v-model="block.show_change" :label="t('receipts.showChange')" />
    </template>

    <template v-else-if="block.type === 'totals'">
      <div class="flex flex-wrap gap-3">
        <USwitch
          v-for="row in totalRows"
          :key="row"
          :model-value="(block.show ?? []).includes(row)"
          :label="t(`receipts.total.${row}`)"
          @update:model-value="(value: boolean) => toggleRow(row, value)"
        />
      </div>
      <p class="text-muted text-xs">
        {{ t('receipts.totalsHint') }}
      </p>
    </template>

    <template v-else-if="block.type === 'field_list'">
      <div
        v-for="(field, position) in block.fields ?? []"
        :key="position"
        class="flex items-end gap-2"
      >
        <UFormField :label="t('receipts.fieldLabel')" class="grow">
          <UInput v-model="field.label" class="w-full" />
        </UFormField>
        <UFormField :label="t('receipts.fieldValue')" class="grow">
          <UInput v-model="field.value" class="w-full" />
        </UFormField>
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="xs"
          class="mb-1"
          :aria-label="t('receipts.removeField')"
          @click="removeField(position)"
        />
      </div>

      <UButton
        icon="i-lucide-plus"
        color="neutral"
        variant="subtle"
        size="xs"
        class="self-start"
        :label="t('receipts.addField')"
        @click="addField"
      />
    </template>

    <p v-else class="text-muted text-xs">
      {{ t(`receipts.blockHint.${block.type}`) }}
    </p>
  </div>
</template>
