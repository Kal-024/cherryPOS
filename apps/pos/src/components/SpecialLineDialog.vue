<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiFetch, firstApiErrorMessage } from '../composables/httpClient'
import { useCart } from '../composables/useCart'
import { useOperator } from '../composables/useOperator'

/**
 * Las dos válvulas de escape del catálogo (D-03, H2.9).
 *
 * **Siempre hay algo que vender que no está cargado.** Sin esto el cajero
 * inventa: cobra el ítem parecido, o teclea un precio sobre otro producto, y el
 * inventario y el reporte quedan mintiendo sin que nadie lo note. La válvula
 * existe justamente para que ese desvío quede registrado como lo que es.
 *
 * Dos formas distintas, y la diferencia importa:
 *
 *  - **Producto sin catálogo** — lleva cantidad y precio unitario. Es mercadería
 *    que se vendió y todavía no está cargada.
 *  - **Cobrar un monto** — un importe suelto: mano de obra, un servicio, un
 *    ajuste. No tiene cantidad porque no hay unidades que contar.
 *
 * El control es el de la decisión, literal: **cinco por cajero por día**,
 * ampliable o ilimitado por configuración, y el supervisor **siempre** recibe el
 * aviso con el detalle, dentro o fuera del límite. Eso último se dice en la
 * pantalla: un control que el cajero descubre después se siente una trampa, y
 * uno que conoce de antemano es un acuerdo.
 *
 * El cupo se pregunta **al abrir**, no al sexto intento con el cliente delante.
 */
const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{ added: [] }>()

const { t } = useI18n()
const cart = useCart()
const { can } = useOperator()

interface Quota {
  used_today: number
  /** Nulo es ilimitado, que es la opción de D-03 para quien siempre puede. */
  limit: number | null
  remaining: number | null
  may_authorize_self: boolean
}

const mode = ref<'temporary' | 'amount'>('temporary')
const description = ref('')
const price = ref('')
const qty = ref('1')
const supervisorCode = ref('')
const supervisorPin = ref('')
const error = ref('')
const saving = ref(false)
const quota = ref<Quota | null>(null)

const firstField = ref<{ $el?: HTMLElement } | null>(null)

const modes = computed(() => [
  { label: t('special.temporary'), value: 'temporary' as const },
  { label: t('special.amount'), value: 'amount' as const }
])

/** Sin permiso propio, o pasado el tope: lo firma el supervisor. */
const needsAuthorization = computed(() => {
  if (!can('pos_sale.temporary_item')) return true

  return quota.value !== null && quota.value.remaining === 0
})

const canConfirm = computed(() => {
  if (description.value.trim() === '' || Number(price.value) <= 0) return false
  if (mode.value === 'temporary' && Number(qty.value) <= 0) return false

  return !needsAuthorization.value || (supervisorCode.value !== '' && supervisorPin.value !== '')
})

watch(open, async (isOpen) => {
  if (!isOpen) return

  description.value = ''
  price.value = ''
  qty.value = '1'
  supervisorCode.value = ''
  supervisorPin.value = ''
  error.value = ''
  quota.value = null

  try {
    quota.value = await apiFetch<Quota>('/special-lines/quota')
  } catch {
    // El cupo es informativo: no saberlo no impide intentarlo, y el servidor
    // rechaza igual si no alcanza.
  }

  await nextTick()
  firstField.value?.$el?.querySelector('input')?.focus()
})

async function confirm() {
  error.value = ''
  saving.value = true

  try {
    await cart.addLine({
      kind: mode.value,
      description: description.value.trim(),
      // El monto no lleva cantidad: no hay unidades que contar.
      ...(mode.value === 'temporary'
        ? { qty: qty.value, unit_price: price.value }
        : { amount: price.value }),
      ...(needsAuthorization.value
        ? { supervisor_code: supervisorCode.value, supervisor_pin: supervisorPin.value }
        : {})
    })

    emit('added')
    open.value = false
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="t('special.title')">
    <template #body>
      <div class="flex flex-col gap-3">
        <UTabs
          v-model="mode"
          :items="modes"
          :content="false"
          class="w-full"
        />

        <p class="text-muted text-sm">
          {{ mode === 'temporary' ? t('special.temporaryHint') : t('special.amountHint') }}
        </p>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          :title="error"
        />

        <!-- El nombre no es opcional: es lo único que quedará en el ticket, en el
             reporte y en el aviso al supervisor. -->
        <UFormField :label="t('special.description')" :hint="t('special.descriptionHint')">
          <UInput
            ref="firstField"
            v-model="description"
            class="w-full"
            :placeholder="t('special.descriptionPlaceholder')"
          />
        </UFormField>

        <div class="flex gap-2">
          <UFormField :label="mode === 'temporary' ? t('special.price') : t('special.total')" class="grow">
            <UInput
              v-model="price"
              v-only="'decimal'"
              type="number"
              step="0.01"
              min="0"
              class="w-full"
            />
          </UFormField>

          <UFormField v-if="mode === 'temporary'" :label="t('special.qty')" class="w-28">
            <UInput
              v-model="qty"
              v-only="'decimal'"
              type="number"
              step="0.001"
              min="0"
              class="w-full"
            />
          </UFormField>
        </div>

        <!-- El cupo, a la vista. Descubrir el límite al sexto intento con el
             cliente delante es lo que convierte un control en un obstáculo. -->
        <p v-if="quota" class="text-muted text-sm">
          {{
            quota.limit === null
              ? t('special.quotaUnlimited')
              : t('special.quota', { used: quota.used_today, limit: quota.limit })
          }}
        </p>

        <!-- Se dice antes, no después: un control que el cajero descubre solo
             cuando ya pasó se siente una trampa (P-11). -->
        <UAlert
          color="info"
          variant="subtle"
          :title="t('special.notifies')"
          :description="t('special.notifiesHint')"
        />

        <template v-if="needsAuthorization">
          <UAlert
            color="warning"
            variant="subtle"
            :title="t('special.needsAuthorization')"
          />

          <div class="flex gap-2">
            <UFormField :label="t('supervisor.code')" class="grow">
              <UInput v-model="supervisorCode" v-only="'code'" class="w-full" />
            </UFormField>
            <UFormField :label="t('supervisor.pin')" class="grow">
              <UInput
                v-model="supervisorPin"
                v-only="'digits'"
                type="password"
                class="w-full"
              />
            </UFormField>
          </div>
        </template>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          :label="t('common.cancel')"
          @click="open = false"
        />
        <UButton
          :disabled="!canConfirm"
          :loading="saving"
          :label="t('special.add')"
          @click="confirm"
        />
      </div>
    </template>
  </UModal>
</template>
