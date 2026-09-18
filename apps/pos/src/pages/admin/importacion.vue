<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { type ImportKind, useImports } from '../../composables/useImports'
import { useCatalog } from '../../composables/useCatalog'

/**
 * Importación desde Excel (D-15, H1.6).
 *
 * **Sin esto no hay puesta en marcha**: nadie carga tres mil productos a mano,
 * y es requisito comercial antes que técnico.
 *
 * La pantalla es el flujo de tres pasos, y el del medio es el que OSPOS no
 * tiene: se sube el archivo, **se mira qué va a pasar** y recién entonces se
 * aplica. La previsualización no escribe nada, así que un archivo con la coma
 * decimal equivocada se descubre antes y no después de crear mil productos a
 * un céntimo.
 *
 * El cuarto paso existe por si acaso: revertir. No siempre alcanza —un producto
 * que desde entonces se vendió ya no se borra— y eso se dice fila por fila en
 * vez de fingir que el archivo nunca pasó.
 */
definePage({ meta: { layout: 'admin', permission: 'catalog.import' } })

const { t } = useI18n()
const imports = useImports()
const catalog = useCatalog()
const toast = useToast()

const kind = ref<ImportKind>('products')
const file = ref<File | null>(null)
const fileError = ref('')
const error = ref('')
const fileInput = useTemplateRef<HTMLInputElement>('fileInput')

const columns = computed(() => Object.entries(imports.kinds.value[kind.value] ?? {}))
const batch = computed(() => imports.batch.value)

const kindOptions = computed(() =>
  Object.keys(imports.kinds.value).map((value) => ({ label: t(`imports.kind.${value}`), value }))
)

const applied = computed(() => batch.value?.status === 'applied')
const reverted = computed(() => batch.value?.status === 'reverted')
const canApply = computed(() => batch.value !== null && !applied.value && !reverted.value && batch.value.rows_valid > 0)

/** Filas que no se pudieron deshacer: el archivo no volvió del todo atrás. */
const revertProblems = computed(() =>
  (batch.value?.rows ?? []).filter((row) => row.revert_error !== null)
)

onMounted(async () => {
  try {
    await imports.loadKinds()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
})

function selectFile(candidate: File | null) {
  fileError.value = ''

  if (!candidate) {
    file.value = null

    return
  }

  if (!/\.(xlsx|xls|csv)$/i.test(candidate.name)) {
    fileError.value = t('imports.onlyExcel')
    file.value = null

    return
  }

  file.value = candidate
}

function onDrop(event: DragEvent) {
  selectFile(event.dataTransfer?.files?.[0] ?? null)
}

function onPick(event: Event) {
  selectFile((event.target as HTMLInputElement).files?.[0] ?? null)
}

async function preview() {
  if (!file.value) {
    fileError.value = t('imports.pickFirst')

    return
  }

  error.value = ''

  try {
    await imports.preview(kind.value, file.value)
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

async function apply() {
  error.value = ''

  try {
    await imports.apply()
    toast.add({ title: t('imports.applied'), color: 'success' })

    // Lo importado tiene que estar en la caja sin reiniciar el terminal: el
    // catálogo local es caché, y una caché que no se entera es peor que no
    // tenerla.
    if (kind.value === 'products') await catalog.sync()
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

async function revert() {
  error.value = ''

  try {
    await imports.revert()
    toast.add({ title: t('imports.reverted'), color: 'success' })

    if (kind.value === 'products') await catalog.sync()
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

async function download(action: 'template' | 'data') {
  error.value = ''

  try {
    if (action === 'template') await imports.downloadTemplate(kind.value)
    else await imports.downloadExport(kind.value)
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}

function startOver() {
  imports.reset()
  file.value = null
  fileError.value = ''
  error.value = ''
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :title="error"
    />

    <div class="flex flex-wrap items-end gap-2">
      <UFormField :label="t('imports.kindLabel')">
        <USelect
          v-model="kind"
          :items="kindOptions"
          :disabled="batch !== null"
          class="w-56"
        />
      </UFormField>

      <UButton
        icon="i-lucide-file-down"
        color="neutral"
        variant="subtle"
        :label="t('imports.downloadTemplate')"
        @click="download('template')"
      />

      <UButton
        icon="i-lucide-table"
        color="neutral"
        variant="subtle"
        :label="t('imports.downloadData')"
        @click="download('data')"
      />
    </div>

    <!-- Paso 1: el archivo. -->
    <template v-if="!batch">
      <div class="grid gap-4 lg:grid-cols-[2fr_1fr]">
        <div
          class="rounded-xl border border-dashed border-default p-8 text-center transition hover:border-primary"
          @dragover.prevent
          @drop.prevent="onDrop"
        >
          <UIcon name="i-lucide-upload-cloud" class="text-primary mx-auto mb-3 size-8" />
          <p class="text-muted text-sm">
            {{ t('imports.dropHint') }}
          </p>
          <UButton
            color="primary"
            variant="outline"
            class="mt-3"
            :label="t('imports.selectFile')"
            @click="fileInput?.click()"
          />
          <p class="text-muted mt-2 text-xs">
            {{ t('imports.onlyExcel') }}
          </p>

          <input
            ref="fileInput"
            type="file"
            class="hidden"
            accept=".xlsx,.xls,.csv"
            @change="onPick"
          >

          <p v-if="file" class="mt-3 text-sm font-medium">
            {{ file.name }}
          </p>
          <p v-if="fileError" class="text-error mt-2 text-sm">
            {{ fileError }}
          </p>
        </div>

        <!-- Los encabezados que el importador espera. Pedirle al cliente que
             los adivine es el camino corto a cuarenta archivos rechazados. -->
        <div class="rounded-lg border border-default p-3">
          <p class="mb-2 text-sm font-medium">
            {{ t('imports.columns') }}
          </p>
          <ul class="flex flex-col gap-1 text-sm">
            <li v-for="[name, spec] in columns" :key="name" class="flex items-center gap-2">
              <code class="text-xs">{{ name }}</code>
              <span class="text-muted text-xs">{{ spec.label }}</span>
              <UBadge
                v-if="spec.required"
                color="neutral"
                variant="subtle"
                size="sm"
              >
                {{ t('imports.required') }}
              </UBadge>
            </li>
          </ul>
        </div>
      </div>

      <div class="flex justify-end">
        <UButton
          icon="i-lucide-eye"
          :loading="imports.loading.value"
          :label="t('imports.preview')"
          @click="preview"
        />
      </div>
    </template>

    <!-- Paso 2: qué va a pasar. -->
    <template v-else>
      <div class="flex flex-wrap items-center gap-2">
        <span class="font-medium">{{ batch.filename }}</span>
        <UBadge color="neutral" variant="subtle">
          {{ t(`imports.status.${batch.status}`) }}
        </UBadge>
        <div class="grow" />
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-rotate-ccw"
          :label="t('imports.startOver')"
          @click="startOver"
        />
      </div>

      <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
        <div class="rounded-lg border border-default p-2">
          <span class="text-muted block text-xs">{{ t('imports.rowsTotal') }}</span>
          <span class="tabular-nums">{{ batch.rows_total }}</span>
        </div>
        <div class="rounded-lg border border-default p-2">
          <span class="text-muted block text-xs">{{ t('imports.rowsValid') }}</span>
          <span class="tabular-nums">{{ batch.rows_valid }}</span>
        </div>
        <div class="rounded-lg border border-default p-2">
          <span class="text-muted block text-xs">{{ t('imports.rowsInvalid') }}</span>
          <span class="tabular-nums" :class="{ 'text-error': batch.rows_invalid > 0 }">
            {{ batch.rows_invalid }}
          </span>
        </div>
        <div class="rounded-lg border border-default p-2">
          <span class="text-muted block text-xs">{{ t('imports.rowsApplied') }}</span>
          <span class="tabular-nums">{{ batch.rows_applied }}</span>
        </div>
      </div>

      <!-- Lo que se entendió, no lo que se escribió: es la única forma de ver
           que "1.234,56" se leyó como 1234.56 y no como 1.23. -->
      <div v-if="batch.sample?.length" class="overflow-x-auto rounded-lg border border-default">
        <p class="text-muted border-b border-default px-2 py-1 text-xs">
          {{ t('imports.sample') }}
        </p>
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="row in batch.sample" :key="row.row_number" class="border-t border-default">
              <td class="text-muted w-12 px-2 py-1 text-xs">
                #{{ row.row_number }}
              </td>
              <td class="px-2 py-1">
                <UBadge color="neutral" variant="subtle" size="sm">
                  {{ t(`imports.action.${row.action}`) }}
                </UBadge>
              </td>
              <td class="px-2 py-1 font-mono text-xs">
                {{ Object.entries(row.normalized).map(([key, value]) => `${key}=${value}`).join('  ') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="batch.problems?.length" class="overflow-x-auto rounded-lg border border-error/40">
        <p class="border-b border-error/40 px-2 py-1 text-xs">
          {{ t('imports.problems', { count: batch.problems.length }) }}
        </p>
        <table class="w-full text-sm">
          <tbody>
            <tr v-for="problem in batch.problems" :key="problem.row_number" class="border-t border-default">
              <td class="text-muted w-12 px-2 py-1 text-xs">
                #{{ problem.row_number }}
              </td>
              <td class="text-error px-2 py-1">
                {{ problem.errors.join(' · ') }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Revertir no siempre alcanza: se dice fila por fila en vez de fingir
           que el archivo nunca pasó. -->
      <UAlert
        v-if="revertProblems.length > 0"
        color="warning"
        variant="subtle"
        :title="t('imports.revertPartial', { count: revertProblems.length })"
        :description="revertProblems.map((row) => `#${row.row_number}: ${row.revert_error}`).join(' · ')"
      />

      <div class="flex justify-end gap-2">
        <UButton
          v-if="applied"
          color="neutral"
          variant="subtle"
          icon="i-lucide-undo-2"
          :loading="imports.working.value"
          :label="t('imports.revert')"
          @click="revert"
        />
        <UButton
          v-if="canApply"
          icon="i-lucide-check"
          :loading="imports.working.value"
          :label="t('imports.apply', { count: batch.rows_valid })"
          @click="apply"
        />
      </div>
    </template>
  </div>
</template>
