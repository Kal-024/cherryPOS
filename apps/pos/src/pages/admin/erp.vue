<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { type OutboxEntry, useErpOutbox } from '../../composables/useErpOutbox'
import { useOperator } from '../../composables/useOperator'
import { amount } from '../../utils/money'

/**
 * Bandeja de envíos al ERP (P4, F1-C).
 *
 * Existe porque el POS **cierra la venta sin el ERP**: si el enlace está caído o
 * el ERP rechaza el documento, el ticket ya se cobró y alguien tiene que ver qué
 * pasó después. Esta es esa pantalla.
 *
 * Dos cosas que se muestran arriba porque no pueden esperar a que alguien baje:
 *
 *  - **`tax_difference` distinto de cero.** El documento es válido y aun así es
 *    alarma: significa que el motor del POS y el del ERP están calculando
 *    distinto, y una diferencia sistemática se repite cada día hasta que alguien
 *    mire. Nunca se corrige en silencio.
 *  - **Las excepciones**, que son lo único que exige decisión humana.
 *
 * Reintentar es para cuando la causa se arregló del otro lado. Cerrar a mano es
 * para lo que el ERP no va a aceptar nunca, y **no borra**: el ticket sigue
 * existiendo con su error y con quién lo dio por cerrado.
 */
definePage({ meta: { layout: 'admin', permission: 'erp_outbox.read' } })

const { t } = useI18n()
const outbox = useErpOutbox()
const { can } = useOperator()
const toast = useToast()

const status = ref<OutboxEntry['status'] | null>(null)
const onlyDifferences = ref(false)
const includeResolved = ref(false)

const detail = ref<OutboxEntry | null>(null)
const resolving = ref<OutboxEntry | null>(null)
const reason = ref('')

const canResolve = computed(() => can('erp_outbox.resolve'))

const statusOptions = computed(() => [
  { label: t('erp.allStatuses'), value: null },
  ...(['exception', 'pending', 'sending', 'sent'] as const).map((value) => ({
    label: t(`erp.status.${value}`),
    value
  }))
])

const resolving2 = ref<{ id: string, label: string } | null>(null)
const resolutionNote = ref('')

onMounted(async () => {
  await Promise.all([
    refresh(),
    outbox.loadStatus().catch(() => undefined),
    outbox.loadReconciliation().catch(() => undefined)
  ])
})

async function pullMasters() {
  try {
    const result = await outbox.pullMasters()

    toast.add({
      title: t('erp.mastersPulled', {
        products: result.products.created + result.products.updated,
        customers: result.customers.created + result.customers.updated
      }),
      color: 'success'
    })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function confirmReconciliation() {
  if (!resolving2.value) return

  try {
    await outbox.resolveReconciliation(resolving2.value.id, resolutionNote.value)
    resolving2.value = null
    resolutionNote.value = ''
    await outbox.loadStatus().catch(() => undefined)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

watch([status, onlyDifferences, includeResolved], refresh)

async function refresh() {
  try {
    await outbox.list({
      status: status.value,
      withDifference: onlyDifferences.value,
      unresolved: !includeResolved.value
    })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function pullSettings() {
  try {
    const applied = await outbox.pullSettings()
    const count = Object.keys(applied ?? {}).length

    toast.add({ title: t('erp.settingsPulled', { count }), color: 'success' })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function openDetail(entry: OutboxEntry) {
  try {
    detail.value = await outbox.find(entry.id)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function retry(entry: OutboxEntry) {
  try {
    await outbox.retry(entry.id)
    toast.add({ title: t('erp.requeued'), color: 'success' })
    await refresh()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function confirmResolve() {
  if (!resolving.value) return

  try {
    await outbox.resolve(resolving.value.id, reason.value)
    toast.add({ title: t('erp.resolved'), color: 'success' })
    resolving.value = null
    reason.value = ''
    await refresh()
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

function tone(entry: OutboxEntry): 'error' | 'warning' | 'success' | 'neutral' {
  if (entry.status === 'exception') return 'error'
  if (entry.status === 'pending' || entry.status === 'sending') return 'warning'

  return entry.status === 'sent' ? 'success' : 'neutral'
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <!-- La divergencia entre los dos motores de cálculo no espera: se avisa
         arriba, antes de cualquier filtro. -->
    <UAlert
      v-if="outbox.divergences.value.length > 0"
      color="error"
      variant="subtle"
      icon="i-lucide-alert-triangle"
      :title="t('erp.divergenceTitle', { count: outbox.divergences.value.length })"
      :description="t('erp.divergenceHint')"
    />

    <!-- Lo primero que pregunta el encargado cuando algo no cuadra: ¿esto está
         conectado, desde cuándo, y qué falta mandar? -->
    <section v-if="outbox.status.value" class="flex flex-col gap-2 rounded-lg border border-default p-3">
      <div class="flex flex-wrap items-center gap-2">
        <UBadge :color="outbox.status.value.configured ? 'success' : 'neutral'" variant="subtle">
          {{ outbox.status.value.configured ? t('erp.linked') : t('erp.standalone') }}
        </UBadge>

        <span v-if="outbox.status.value.base_url" class="text-muted text-xs">
          {{ outbox.status.value.base_url }}
        </span>

        <div class="grow" />

        <span class="text-muted text-xs">
          {{ outbox.status.value.settings_synced_at
            ? t('erp.syncedAt', { when: new Date(outbox.status.value.settings_synced_at).toLocaleString() })
            : t('erp.neverSynced') }}
        </span>

        <UButton
          v-if="canResolve && outbox.status.value.configured"
          icon="i-lucide-download"
          color="neutral"
          variant="subtle"
          size="xs"
          :loading="outbox.saving.value"
          :label="t('erp.pullSettings')"
          @click="pullSettings"
        />

        <UButton
          v-if="canResolve && outbox.status.value.configured"
          icon="i-lucide-refresh-cw"
          color="neutral"
          variant="subtle"
          size="xs"
          :loading="outbox.saving.value"
          :label="t('erp.pullMasters')"
          @click="pullMasters"
        />
      </div>

      <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
        <div>
          <span class="text-muted block text-xs">{{ t('erp.queuePending') }}</span>
          <span class="tabular-nums">{{ outbox.status.value.queue.pending }}</span>
        </div>
        <div>
          <span class="text-muted block text-xs">{{ t('erp.queueExceptions') }}</span>
          <span class="tabular-nums" :class="{ 'text-error font-medium': outbox.status.value.queue.exceptions > 0 }">
            {{ outbox.status.value.queue.exceptions }}
          </span>
        </div>
        <div>
          <span class="text-muted block text-xs">{{ t('erp.queueSentToday') }}</span>
          <span class="tabular-nums">{{ outbox.status.value.queue.sent_today }}</span>
        </div>
        <div>
          <span class="text-muted block text-xs">{{ t('erp.effectiveProfile') }}</span>
          <span>{{ outbox.status.value.effective.layout_profile }}</span>
        </div>
      </div>

      <!-- Lo que rige hoy: es lo que hay que mirar cuando la caja se comporta
           distinto de lo que alguien esperaba. -->
      <p class="text-muted text-xs">
        {{ t('erp.effectiveHint', {
          hours: outbox.status.value.effective.offline_max_hours,
          pin: outbox.status.value.effective.offline_pin_mode,
          shift: outbox.status.value.effective.require_shift ? t('common.yes') : t('common.no')
        }) }}
      </p>
    </section>

    <!-- Bandeja de conciliación (§12.8, Q-04): avisa y **no bloquea**. Lo
         pendiente se sigue vendiendo; lo que no se puede es fusionarlo solo. -->
    <section v-if="outbox.reconciliation.value.length > 0" class="rounded-lg border border-warning/50 p-3">
      <div class="mb-2 flex items-center gap-2">
        <UIcon name="i-lucide-git-merge" class="text-warning size-4" />
        <span class="font-medium">{{ t('erp.reconciliation', { count: outbox.reconciliation.value.length }) }}</span>
      </div>

      <p class="text-muted mb-2 text-xs">
        {{ t('erp.reconciliationHint') }}
      </p>

      <ul class="flex flex-col gap-1 text-sm">
        <li
          v-for="entry in outbox.reconciliation.value"
          :key="entry.id"
          class="flex items-center gap-2 rounded-md border border-default px-2 py-1"
        >
          <UBadge color="neutral" variant="subtle" size="sm">
            {{ t(`erp.kind.${entry.kind}`) }}
          </UBadge>
          <span class="grow truncate">{{ entry.label }}</span>
          <span v-if="entry.natural_key" class="text-muted font-mono text-xs">{{ entry.natural_key }}</span>
          <UBadge color="warning" variant="subtle" size="sm">
            {{ t(`erp.reasons.${entry.reason}`) }}
          </UBadge>
          <UButton
            v-if="canResolve"
            icon="i-lucide-check"
            color="neutral"
            variant="ghost"
            size="xs"
            :aria-label="t('erp.resolve')"
            @click="resolving2 = { id: entry.id, label: entry.label }"
          />
        </li>
      </ul>
    </section>

    <div class="flex flex-wrap items-end gap-2">
      <UFormField :label="t('erp.statusLabel')">
        <USelect v-model="status" :items="statusOptions" class="w-48" />
      </UFormField>

      <USwitch v-model="onlyDifferences" :label="t('erp.onlyDifferences')" class="pb-2" />
      <USwitch v-model="includeResolved" :label="t('erp.includeResolved')" class="pb-2" />
    </div>

    <div
      v-if="!outbox.loading.value && outbox.entries.value.length === 0"
      class="text-muted flex flex-col items-center gap-1 rounded-lg border border-default p-10 text-center"
    >
      <UIcon name="i-lucide-check-circle" class="size-8" />
      <p class="font-medium">
        {{ t('erp.empty') }}
      </p>
      <p class="text-sm">
        {{ t('erp.emptyHint') }}
      </p>
    </div>

    <div v-else class="overflow-x-auto rounded-lg border border-default">
      <table class="w-full text-sm">
        <thead class="bg-elevated text-muted">
          <tr class="text-left">
            <th class="px-2 py-1 font-medium">
              {{ t('erp.sale') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('erp.statusLabel') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('erp.error') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('erp.attempts') }}
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('erp.document') }}
            </th>
            <th class="px-2 py-1 text-right font-medium">
              {{ t('erp.difference') }}
            </th>
            <th class="w-28" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="entry in outbox.entries.value"
            :key="entry.id"
            class="cursor-pointer border-t border-default hover:bg-elevated/60"
            :class="{ 'opacity-60': entry.resolved_at !== null }"
            @click="openDetail(entry)"
          >
            <td class="px-2 py-1">
              {{ entry.sale_number ?? '—' }}
              <span class="text-muted ml-1 text-xs tabular-nums">{{ amount(entry.sale_total) }}</span>
            </td>
            <td class="px-2 py-1">
              <UBadge :color="tone(entry)" variant="subtle">
                {{ t(`erp.status.${entry.status}`) }}
              </UBadge>
              <UBadge
                v-if="entry.resolved_at"
                color="neutral"
                variant="subtle"
                class="ml-1"
              >
                {{ t('erp.closedByHand') }}
              </UBadge>
            </td>
            <td class="px-2 py-1 font-mono text-xs">
              {{ entry.error_code ?? '—' }}
            </td>
            <td class="text-muted px-2 py-1 text-right tabular-nums">
              {{ entry.attempts }}
            </td>
            <td class="text-muted px-2 py-1 text-xs">
              {{ entry.erp_document_number ?? '—' }}
              <UBadge
                v-if="entry.duplicate"
                color="neutral"
                variant="subtle"
                size="sm"
              >
                {{ t('erp.duplicate') }}
              </UBadge>
            </td>
            <td
              class="px-2 py-1 text-right tabular-nums"
              :class="{ 'text-error font-medium': entry.tax_difference !== null && Number(entry.tax_difference) !== 0 }"
            >
              {{ entry.tax_difference ? amount(entry.tax_difference) : '—' }}
            </td>
            <td class="px-2 py-1 text-right" @click.stop>
              <template v-if="canResolve && entry.status === 'exception' && !entry.resolved_at">
                <UButton
                  icon="i-lucide-refresh-cw"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :loading="outbox.saving.value"
                  :aria-label="t('erp.retry')"
                  @click="retry(entry)"
                />
                <UButton
                  icon="i-lucide-check"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  :aria-label="t('erp.resolve')"
                  @click="resolving = entry"
                />
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <UModal
      :open="detail !== null"
      :title="t('erp.detailTitle', { number: detail?.sale_number ?? '' })"
      @update:open="(value: boolean) => { if (!value) detail = null }"
    >
      <template #body>
        <div v-if="detail" class="flex flex-col gap-2 text-sm">
          <p v-if="detail.error_message" class="text-error">
            {{ detail.error_message }}
          </p>

          <p class="text-muted text-xs">
            {{ t('erp.payloadHint') }}
          </p>

          <!-- El sobre tal como viajó: es lo único que permite explicar por qué
               el ERP lo rechazó. -->
          <pre class="overflow-x-auto rounded-lg border border-default bg-elevated p-2 text-xs">{{ JSON.stringify(detail.payload, null, 2) }}</pre>
        </div>
      </template>
    </UModal>

    <UModal
      :open="resolving2 !== null"
      :title="t('erp.reconciliationResolve', { label: resolving2?.label ?? '' })"
      @update:open="(value: boolean) => { if (!value) { resolving2 = null; resolutionNote = '' } }"
    >
      <template #body>
        <div class="flex flex-col gap-3">
          <p class="text-sm">
            {{ t('erp.reconciliationResolveHint') }}
          </p>

          <UFormField :label="t('erp.resolutionNote')" required>
            <UInput v-model="resolutionNote" autofocus class="w-full" />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="resolving2 = null"
            />
            <UButton
              :disabled="resolutionNote.trim() === ''"
              :loading="outbox.saving.value"
              :label="t('erp.resolve')"
              @click="confirmReconciliation"
            />
          </div>
        </div>
      </template>
    </UModal>

    <UModal
      :open="resolving !== null"
      :title="t('erp.resolve')"
      @update:open="(value: boolean) => { if (!value) { resolving = null; reason = '' } }"
    >
      <template #body>
        <div class="flex flex-col gap-3">
          <p class="text-sm">
            {{ t('erp.resolveHint') }}
          </p>

          <UFormField :label="t('erp.reason')" required :help="t('erp.reasonHint')">
            <UInput v-model="reason" autofocus class="w-full" />
          </UFormField>

          <div class="flex justify-end gap-2">
            <UButton
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="resolving = null"
            />
            <UButton
              :disabled="reason.trim() === ''"
              :loading="outbox.saving.value"
              :label="t('erp.resolve')"
              @click="confirmResolve"
            />
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>
