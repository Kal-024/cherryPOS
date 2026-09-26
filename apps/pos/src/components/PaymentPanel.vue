<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useCart } from '../composables/useCart'
import { useOffline } from '../composables/useOffline'
import { useOperator } from '../composables/useOperator'
import { useShift } from '../composables/useShift'
import { useTerminal } from '../composables/useTerminal'
import { amount } from '../utils/money'

/**
 * El cobro (B-02, Q-06, H3).
 *
 * Varios pagos por venta: mitad efectivo y mitad tarjeta es lo normal, no la
 * excepción. Y **doble moneda**: en Nicaragua el cliente entrega dólares, se
 * cobra a la tasa del turno y el vuelto sale en córdobas.
 *
 * El vuelto no se calcula acá. Lo devuelve el servidor con cada pago
 * registrado, porque calcularlo en el terminal abriría una tercera
 * implementación de las fórmulas — justo lo que el proyecto decidió no tener.
 */
/**
 * Qué se sabe de la venta recién cerrada.
 *
 * Va el identificador además del número porque el comprobante se pide por
 * identificador, y va `offline` porque **sin servidor no hay comprobante que
 * pedir**: el ticket todavía vive en la bandeja de salida de este equipo.
 */
export interface ClosedSale {
  id: string
  number: string | null
  offline: boolean
}

const emit = defineEmits<{ closed: [ClosedSale | null] }>()

const { t } = useI18n()
const cart = useCart()
const offline = useOffline()
const shift = useShift()
const { branch } = useTerminal()
const { employee } = useOperator()

const method = ref<'cash' | 'card' | 'credit' | 'transfer' | 'other'>('cash')
const currency = ref('NIO')
const received = ref('')
const reference = ref('')
/** El contenedor, por el mismo motivo que en `SaleSearch`. */
const root = ref<HTMLElement | null>(null)
const error = ref('')

const baseCurrency = computed(() => cart.sale.value?.currency_code ?? 'NIO')
const secondaryCurrency = 'USD'

/**
 * La tasa del turno, para mostrarla junto al campo.
 *
 * El cajero la canta cuando el cliente pregunta "¿a cuánto me lo tomás?", y
 * verla evita la discusión. El cálculo lo hace el servidor con la tasa que el
 * turno congeló al abrir (Q-06).
 */
const shiftRate = computed(() => shift.summary.value?.shift.exchange_rate ?? null)

/**
 * Propina (G-16).
 *
 * Aparece **antes** de registrar el pago, no después: el cajero necesita saber
 * cuánto cobrar, y una propina agregada con el ticket ya saldado obligaría a
 * volver atrás. Se apaga por configuración, porque en un mostrador de retail el
 * renglón solo estorba.
 */
const tipEnabled = computed(() => offline.settings.value?.tip_enabled ?? false)
const tip = computed(() => cart.sale.value?.tip_amount ?? '0.00')
const hasTip = computed(() => Number(tip.value) !== 0)
const tipInput = ref('')

const suggestedPercent = computed(() => offline.settings.value?.tip_suggested_percent ?? '10')

/**
 * Lo que el porcentaje sugerido da sobre esta cuenta.
 *
 * **Es lo único que el terminal calcula con dinero, y no es el dinero de la
 * venta:** la propina no lleva impuesto, no se reparte entre líneas y no entra
 * en ningún total fiscal. Lo que se manda al servidor es el importe, y el
 * servidor lo vuelve a validar.
 */
function suggested(): string {
  const total = Number(cart.sale.value?.total ?? 0)
  const percent = Number(suggestedPercent.value)

  return (Math.round(total * percent) / 100).toFixed(2)
}

async function applyTip(value: string) {
  error.value = ''

  try {
    await cart.setTip(value)
    tipInput.value = ''
  } catch {
    error.value = cart.lastError.value
  }
}

const due = computed(() => cart.sale.value?.balance ?? '0.00')

/** Devolver es lo contrario de cobrar: los importes van en negativo. */
const isRefund = computed(() => cart.sale.value?.sale_type === 'refund')

/**
 * ¿Está saldada?
 *
 * En una venta, cuando no falta nada por cobrar. En una **devolución**, cuando
 * se entregó exactamente lo que había que devolver: con el criterio de la venta
 * —"el saldo no es positivo"— una devolución nacía dada por saldada, porque su
 * saldo empieza en negativo. El cajero podía cerrarla sin haber entregado el
 * dinero, y el arqueo lo descubría al final del turno.
 */
const settled = computed(() => {
  const lines = cart.sale.value?.lines?.length ?? 0

  if (lines === 0) return false

  return isRefund.value ? Number(due.value) === 0 : Number(due.value) <= 0
})

const methods = computed(() => [
  { value: 'cash', label: t('payment.cash'), icon: 'i-lucide-banknote' },
  { value: 'card', label: t('payment.card'), icon: 'i-lucide-credit-card' },
  { value: 'transfer', label: t('payment.transfer'), icon: 'i-lucide-arrow-left-right' },
  { value: 'credit', label: t('payment.credit'), icon: 'i-lucide-notebook-pen' }
])

/**
 * El crédito necesita a quién cargárselo, y hay que decirlo **antes**.
 *
 * Sin esto el aviso llegaba al cerrar —"el cliente no tiene cuenta de crédito
 * abierta"— con la venta cobrada a medias y sin explicar que lo que faltaba era
 * elegir al cliente en la pantalla anterior.
 */
const creditNeedsCustomer = computed(() =>
  method.value === 'credit' && cart.sale.value?.customer_id == null
)

watch(method, () => {
  // Cambiar de medio devuelve la moneda a la base: solo el efectivo se recibe
  // en dólares, y dejar USD seleccionado tras elegir tarjeta invita al error.
  if (method.value !== 'cash') currency.value = baseCurrency.value
})

function focusAmount() {
  void nextTick(() => {
    root.value?.querySelector('input')?.focus()
  })
}

/** El cajero cobra exacto casi siempre: un botón ahorra teclear el total. */
function exact() {
  received.value = due.value
  void add()
}

async function add() {
  error.value = ''

  // Cero no es un pago. Y el signo lo manda el tipo de venta: en una devolución
  // el dinero sale, así que el importe es negativo — con la guarda de la venta,
  // el pago no se registraba nunca y la devolución no se podía cerrar.
  const entered = Number(received.value)

  if (received.value === '' || entered === 0) return
  if (isRefund.value ? entered > 0 : entered < 0) return

  try {
    await cart.addPayment({
      method: method.value,
      amount: received.value,
      currency_code: currency.value
    })

    received.value = ''
    reference.value = ''
    focusAmount()
  } catch {
    error.value = cart.lastError.value
  }
}

async function confirm() {
  error.value = ''

  try {
    // Sin servidor el ticket se numera con el bloque reservado de esta
    // terminal, y para eso hacen falta la sucursal y el cajero: los dos van en
    // el ticket que se encola.
    const degraded = cart.degraded.value
    const closed = await cart.close(branch.value?.code ?? '001', employee.value?.id ?? '')

    emit('closed', closed ? { id: closed.id, number: closed.number, offline: degraded } : null)
  } catch {
    error.value = cart.lastError.value
  }
}

defineExpose({ focusAmount })
</script>

<template>
  <div ref="root" class="flex flex-col gap-3">
    <!-- Lo que falta cobrar, grande: es el número que el cajero mira mientras
         cuenta el efectivo que le están dando. -->
    <div class="flex items-baseline justify-between rounded-lg bg-elevated px-4 py-3">
      <span class="text-muted">{{ t('payment.due') }}</span>
      <span class="pos-total" :class="settled ? 'text-success' : ''">
        {{ amount(due) }}
      </span>
    </div>

    <!-- El vuelto **no se repite acá**: lo canta el bloque de totales, que está
         justo encima y se lee desde la misma distancia. Mostrarlo en los dos
         sitios ponía cuatro números en pantalla —total, vuelto, a cobrar,
         vuelto— y obligaba a leerlos todos para saber cuál mandaba. -->

    <!-- Falta elegir a quién cargárselo, y se dice acá y no al cerrar. -->
    <UAlert
      v-if="creditNeedsCustomer"
      color="warning"
      variant="subtle"
      :title="t('payment.creditNeedsCustomer')"
      :description="t('payment.creditNeedsCustomerHint')"
    />

    <!-- La propina, antes de cobrar: después del pago habría que volver atrás. -->
    <div v-if="tipEnabled" class="flex flex-col gap-2 rounded-lg border border-default px-3 py-2">
      <div class="flex items-baseline justify-between">
        <span class="text-muted text-sm">{{ t('payment.tipTitle') }}</span>
        <span v-if="hasTip" class="pos-amount">{{ amount(tip) }}</span>
      </div>

      <div class="flex gap-2">
        <UButton
          color="neutral"
          variant="subtle"
          size="sm"
          :disabled="cart.busy.value"
          @click="applyTip(suggested())"
        >
          {{ t('payment.tipSuggest', { percent: suggestedPercent }) }} · {{ amount(suggested()) }}
        </UButton>

        <UInput
          v-model="tipInput"
          v-only="'decimal'"
          type="number"
          step="0.01"
          min="0"
          size="sm"
          class="w-28"
          :placeholder="t('payment.tipTitle')"
          @keydown.enter.prevent="applyTip(tipInput)"
        />

        <UButton
          v-if="hasTip"
          color="neutral"
          variant="ghost"
          size="sm"
          :disabled="cart.busy.value"
          @click="applyTip('0')"
        >
          {{ t('payment.tipClear') }}
        </UButton>
      </div>

      <p class="text-muted text-xs">
        {{ t('payment.tipHint') }}
      </p>
    </div>

    <!--
      Medios de pago: icono grande, nombre en el globo.

      El nombre dentro del botón obligaba a repartir cuatro palabras de largo
      desigual en cuatro columnas —"Transferencia" contra "Crédito"— y el bloque
      quedaba desparejo. El icono se reconoce antes que la palabra, que es lo que
      importa cuando se toca sin mirar, y el nombre sigue disponible para quien
      duda: en el globo y en el lector de pantalla, no ocupando la fila.
    -->
    <div class="grid grid-cols-4 gap-2">
      <UTooltip
        v-for="option in methods"
        :key="option.value"
        :text="option.label"
      >
        <UButton
          :icon="option.icon"
          :color="method === option.value ? 'primary' : 'neutral'"
          :variant="method === option.value ? 'solid' : 'subtle'"
          block
          size="lg"
          class="h-11"
          :aria-label="option.label"
          @click="method = option.value as typeof method"
        />
      </UTooltip>
    </div>

    <div class="flex gap-2">
      <UInput
        v-model="received"
        v-only="'decimal'"
        type="number"
        step="0.01"
        min="0"
        size="lg"
        class="grow"
        :placeholder="t('payment.amount')"
        @keydown.enter.prevent="add"
      />

      <!--
        Doble moneda solo para efectivo (Q-06): una tarjeta se cobra en la
        moneda de la terminal bancaria, no en la que el cliente prefiera.

        Dos botones y no una lista desplegable: son **dos** opciones, y elegir
        entre dos abriendo un menú cuesta tres gestos donde alcanza uno. El
        código de tres letras se queda a la vista porque ahí el dato *es* la
        palabra; lo que va al globo es qué significa el par.
      -->
      <UTooltip v-if="method === 'cash'" :text="t('payment.currency')">
        <div class="flex gap-1">
          <UButton
            v-for="code in [baseCurrency, secondaryCurrency]"
            :key="code"
            :color="currency === code ? 'primary' : 'neutral'"
            :variant="currency === code ? 'solid' : 'subtle'"
            size="lg"
            class="w-16 justify-center"
            @click="currency = code"
          >
            {{ code }}
          </UButton>
        </div>
      </UTooltip>

      <UTooltip :text="t('payment.add')">
        <UButton
          icon="i-lucide-plus"
          size="lg"
          :loading="cart.busy.value"
          :disabled="creditNeedsCustomer"
          :aria-label="t('payment.add')"
          @click="add"
        />
      </UTooltip>
    </div>

    <p v-if="currency !== baseCurrency" class="text-muted text-sm">
      {{ shiftRate ? t('payment.rateHint', { rate: shiftRate }) : t('payment.noRate') }}
    </p>

    <UButton
      v-if="!settled"
      color="neutral"
      variant="subtle"
      block
      @click="exact"
    >
      {{ t('payment.exact') }} · {{ amount(due) }}
    </UButton>

    <ul v-if="cart.payments.value.length > 0" class="divide-y divide-default text-sm">
      <li
        v-for="payment in cart.payments.value"
        :key="payment.id"
        class="flex items-center gap-2 py-1"
      >
        <span class="grow">
          {{ t(`payment.${payment.method}`) }}
          <span v-if="payment.currency_code !== baseCurrency" class="text-muted">
            · {{ payment.currency_code }} {{ amount(payment.amount) }}
            <span v-if="payment.exchange_rate" class="text-xs">
              @ {{ payment.exchange_rate }}
            </span>
          </span>
        </span>
        <span class="pos-amount">{{ amount(payment.amount_base) }}</span>
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="xs"
          :aria-label="t('payment.remove')"
          @click="cart.removePayment(payment.id)"
        />
      </li>
    </ul>

    <!-- Errores con acción, nunca callejones sin salida. -->
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :description="error"
    />

    <UButton
      size="xl"
      block
      :disabled="!settled"
      :loading="cart.busy.value"
      icon="i-lucide-check"
      @click="confirm"
    >
      {{ t('payment.confirm') }}
    </UButton>
  </div>
</template>
