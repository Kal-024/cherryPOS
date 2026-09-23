<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import {
  BLOCK_TYPES,
  type BlockType,
  PAPER_WIDTHS,
  type Paper,
  type Preview,
  type ReceiptTemplate,
  type TemplateBlock,
  newBlock,
  useReceiptTemplates
} from '../../composables/useReceiptTemplates'
import { useOperator } from '../../composables/useOperator'
import ReceiptBlockEditor from '../../components/ReceiptBlockEditor.vue'
import ReceiptPreview from '../../components/ReceiptPreview.vue'

/**
 * Plantillas de comprobante (D-11, H5).
 *
 * **Acá se edita el ticket, no el documento contable.** El POS entrega el papel
 * del mostrador; la numeración fiscal, el asiento y la declaración viven en el
 * ERP, que ya los implementa. Esta pantalla no emite nada: define cómo se ve lo
 * que la caja imprime.
 *
 * Dos decisiones que se ven en la disposición:
 *
 *  - **La vista previa manda.** Ocupa la mitad derecha y se actualiza sola: sin
 *    ella, configurar una plantilla es teclear a ciegas y descubrir el
 *    resultado en el primer cliente.
 *  - **Las plantillas del sistema no se editan, se duplican.** Son el punto de
 *    retorno cuando alguien deja la suya irreconocible.
 */
definePage({ meta: { layout: 'admin', permission: 'pos_settings.read' } })

const { t } = useI18n()
const templates = useReceiptTemplates()
const { can } = useOperator()
const toast = useToast()

const selected = ref<ReceiptTemplate | null>(null)
const blocks = ref<TemplateBlock[]>([])
const paper = ref<Paper>('thermal_80')
const preview = ref<Preview | null>(null)
const previewError = ref('')
const blockToAdd = ref<BlockType>('text')

const canEdit = computed(() => can('pos_settings.update'))
const isSystem = computed(() => selected.value?.is_system === true && selected.value.branch_id === null)

const paperOptions = computed(() =>
  (Object.keys(PAPER_WIDTHS) as Paper[]).map((value) => ({
    label: `${t(`receipts.paper.${value}`)} · ${PAPER_WIDTHS[value]} ${t('receipts.chars')}`,
    value
  }))
)

const blockOptions = computed(() =>
  BLOCK_TYPES.map((value) => ({ label: t(`receipts.block.${value}`), value }))
)

onMounted(async () => {
  try {
    await templates.list()
    const first = templates.templates.value[0]
    if (first) await select(first)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
})

// La vista previa la dibuja el servidor, que es quien sabe cuántos caracteres
// entran por línea y cómo se recortan. Calcularla acá sería una segunda
// implementación del renderizador, con las mismas consecuencias que tendría una
// segunda del motor de cálculo.
let debounce: ReturnType<typeof setTimeout> | undefined

watch([blocks, paper], () => {
  clearTimeout(debounce)
  debounce = setTimeout(renderPreview, 400)
}, { deep: true })

async function select(template: ReceiptTemplate) {
  try {
    const full = await templates.find(template.id)
    selected.value = full
    blocks.value = structuredClone(full.content.blocks ?? [])
    paper.value = full.paper
    await renderPreview()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function renderPreview() {
  previewError.value = ''

  try {
    preview.value = await templates.preview({ blocks: blocks.value }, paper.value)
  } catch (err) {
    // Un bloque inválido no puede dejar la pantalla en blanco: se dice qué pasa
    // y se conserva la última vista buena.
    previewError.value = firstApiErrorMessage(err)
  }
}

function addBlock() {
  blocks.value = [...blocks.value, newBlock(blockToAdd.value)]
}

function moveBlock(index: number, offset: number) {
  const next = [...blocks.value]
  const target = index + offset
  const moved = next[index]
  const displaced = next[target]

  if (!moved || !displaced) return

  next[index] = displaced
  next[target] = moved
  blocks.value = next
}

function removeBlock(index: number) {
  blocks.value = blocks.value.filter((_, position) => position !== index)
}

async function save() {
  if (!selected.value) return

  try {
    const saved = await templates.update(selected.value.id, {
      content: { blocks: blocks.value },
      paper: paper.value
    })

    selected.value = saved
    toast.add({ title: t('receipts.saved'), color: 'success' })
    await templates.list()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

/**
 * Dice cuál se imprime.
 *
 * Hasta acá no había forma de decidirlo: la elección la hacía `resolve()` en
 * silencio, prefiriendo la de la sucursal sobre la del sistema. Duplicar para
 * probar un diseño ya cambiaba el comprobante real.
 */
async function useThis() {
  if (!selected.value) return

  try {
    selected.value = await templates.makeDefault(selected.value.id)
    await templates.list()
    toast.add({ title: t('receipts.nowInUse'), color: 'success' })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function removeTemplate() {
  if (!selected.value) return

  try {
    await templates.remove(selected.value.id)
    selected.value = null
    await templates.list()

    const first = templates.templates.value[0]
    if (first) await select(first)

    toast.add({ title: t('receipts.deleted'), color: 'success' })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function duplicate() {
  if (!selected.value) return

  try {
    const copy = await templates.duplicate(selected.value.id)
    await templates.list()
    await select(copy)
    toast.add({ title: t('receipts.duplicated'), color: 'success' })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="flex min-h-0 flex-col gap-3">
    <div class="flex flex-wrap items-center gap-2">
      <UButton
        v-for="template in templates.templates.value"
        :key="template.id"
        :color="selected?.id === template.id ? 'primary' : 'neutral'"
        :variant="selected?.id === template.id ? 'solid' : 'subtle'"
        size="sm"
        @click="select(template)"
      >
        {{ template.name }}
        <!-- Cuál se imprime, a la vista: era justo lo que no se podía saber. -->
        <UBadge
          v-if="template.is_default"
          color="success"
          size="sm"
          variant="subtle"
        >
          {{ t('receipts.inUse') }}
        </UBadge>
      </UButton>
    </div>

    <UAlert
      v-if="isSystem"
      color="info"
      variant="subtle"
      :title="t('receipts.systemTemplate')"
      :description="t('receipts.systemTemplateHint')"
    />

    <div v-if="selected" class="grid min-h-0 gap-4 lg:grid-cols-2">
      <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-end gap-2">
          <UFormField :label="t('receipts.paperLabel')">
            <USelect
              v-model="paper"
              :items="paperOptions"
              :disabled="isSystem"
              class="w-56"
            />
          </UFormField>

          <div class="grow" />

          <UButton
            v-if="isSystem && canEdit"
            icon="i-lucide-copy"
            color="neutral"
            variant="subtle"
            :loading="templates.saving.value"
            :label="t('receipts.duplicate')"
            @click="duplicate"
          />
          <UButton
            v-else-if="canEdit"
            icon="i-lucide-save"
            :loading="templates.saving.value"
            :label="t('receipts.save')"
            @click="save"
          />

          <UButton
            v-if="canEdit && !selected.is_default"
            icon="i-lucide-check-check"
            color="success"
            variant="subtle"
            :loading="templates.saving.value"
            :label="t('receipts.useThis')"
            @click="useThis"
          />

          <!-- Borrar solo lo propio, y solo lo que no está en uso: el servidor
               rechaza lo demás y acá se oculta para no ofrecer un callejón. -->
          <UButton
            v-if="canEdit && !isSystem && !selected.is_default"
            icon="i-lucide-trash-2"
            color="error"
            variant="ghost"
            :loading="templates.saving.value"
            :label="t('receipts.deleteCopy')"
            @click="removeTemplate"
          />
        </div>

        <div class="flex flex-col gap-2">
          <ReceiptBlockEditor
            v-for="(_, index) in blocks"
            :key="index"
            v-model="blocks[index]!"
            :index="index"
            :count="blocks.length"
            @move="(offset: number) => moveBlock(index, offset)"
            @remove="removeBlock(index)"
          />
        </div>

        <div v-if="canEdit && !isSystem" class="flex items-end gap-2">
          <UFormField :label="t('receipts.addBlock')">
            <USelect v-model="blockToAdd" :items="blockOptions" class="w-48" />
          </UFormField>
          <UButton
            icon="i-lucide-plus"
            color="neutral"
            variant="subtle"
            :label="t('receipts.add')"
            @click="addBlock"
          />
        </div>
      </div>

      <!--
        La vista previa **acompaña** al editor.

        El editor crece hacia abajo tanto como bloques tenga la plantilla, y sin
        esto la previsualización se quedaba arriba: se terminaba de configurar a
        ciegas, mirando un recuadro que ya estaba fuera de la pantalla. Ese era
        además el motivo de que pareciera que los cambios no se reflejaban.
      -->
      <div class="flex flex-col gap-2 lg:sticky lg:top-0 lg:max-h-[calc(100vh-7rem)] lg:self-start lg:overflow-auto">
        <span class="text-sm font-medium">{{ t('receipts.preview') }}</span>

        <UAlert
          v-if="previewError"
          color="error"
          variant="subtle"
          :title="previewError"
        />

        <!-- Monoespaciado y con el ancho exacto del papel: es la única forma de
             ver si un nombre largo se corta antes de que lo vea un cliente. -->
        <ReceiptPreview v-if="preview" :lines="preview.lines" :width="preview.width" />

        <p class="text-muted text-xs">
          {{ t('receipts.previewHint', { width: preview?.width ?? PAPER_WIDTHS[paper] }) }}
        </p>

        <!-- El POS entrega el ticket del mostrador; el documento contable lo
             emite el ERP (P-08, contrato con cherryB). -->
        <p class="text-muted text-xs">
          {{ t('receipts.notAccounting') }}
        </p>
      </div>
    </div>
  </div>
</template>
