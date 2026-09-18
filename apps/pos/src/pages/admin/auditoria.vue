<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { useReports } from '../../composables/useReports'

/**
 * Bitácora de auditoría (G-11, H7.4).
 *
 * **Solo lectura, y no por descuido.** La tabla es de solo inserción del lado
 * del servidor; acá no hay editar ni borrar porque una bitácora que el propio
 * sistema puede reescribir no prueba nada.
 *
 * Cada entrada responde las cuatro preguntas que importan cuando falta dinero o
 * aparece un descuento raro: quién, qué, desde qué terminal y cuándo.
 */
definePage({ meta: { layout: 'admin', permission: 'report.audit_log' } })

const { t } = useI18n()
const reports = useReports()
const toast = useToast()

const today = new Date().toISOString().slice(0, 10)
const from = ref(today)
const to = ref(today)
const event = ref('')

onMounted(refresh)

let debounce: ReturnType<typeof setTimeout> | undefined

watch([from, to, event], () => {
  clearTimeout(debounce)
  debounce = setTimeout(refresh, 250)
})

async function refresh() {
  try {
    await reports.auditLog({ from: from.value, to: to.value, event: event.value.trim() })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

/** El detalle en crudo: lo que cambió, sin interpretarlo. */
function describe(entry: { changes: Record<string, unknown> | null }): string {
  return entry.changes ? JSON.stringify(entry.changes) : ''
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div class="flex flex-wrap items-end gap-2">
      <UFormField :label="t('reports.from')">
        <UInput v-model="from" type="date" class="w-40" />
      </UFormField>

      <UFormField :label="t('reports.to')">
        <UInput v-model="to" type="date" class="w-40" />
      </UFormField>

      <UFormField :label="t('audit.event')" :help="t('audit.eventHint')" class="grow">
        <UInput v-model="event" placeholder="sale." class="w-full" />
      </UFormField>
    </div>

    <div
      v-if="!reports.loading.value && reports.entries.value.length === 0"
      class="text-muted flex flex-col items-center gap-1 rounded-lg border border-default p-10 text-center"
    >
      <UIcon name="i-lucide-scroll-text" class="size-8" />
      <p class="font-medium">
        {{ t('audit.empty') }}
      </p>
    </div>

    <div v-else class="overflow-x-auto rounded-lg border border-default">
      <table class="w-full text-sm">
        <thead class="bg-elevated text-muted">
          <tr class="text-left">
            <th class="px-2 py-1 font-medium">
              {{ t('audit.when') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('audit.who') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('audit.what') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('audit.where') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('audit.detail') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entry in reports.entries.value" :key="entry.id" class="border-t border-default">
            <td class="text-muted px-2 py-1 whitespace-nowrap text-xs">
              {{ new Date(entry.occurred_at).toLocaleString() }}
            </td>
            <td class="px-2 py-1">
              {{ entry.employee_name ?? '—' }}
            </td>
            <td class="px-2 py-1 font-mono text-xs">
              {{ entry.event }}
            </td>
            <td class="text-muted px-2 py-1 text-xs">
              {{ entry.terminal_code ?? '—' }}
            </td>
            <td class="text-muted max-w-md truncate px-2 py-1 font-mono text-xs" :title="describe(entry)">
              {{ describe(entry) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
