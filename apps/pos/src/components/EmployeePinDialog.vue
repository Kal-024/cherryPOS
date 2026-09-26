<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../composables/httpClient'
import { useEmployees } from '../composables/useEmployees'

/**
 * Fijar el PIN de un empleado (D-05, P-11).
 *
 * Dos avisos que la pantalla tiene que dar y no son adorno:
 *
 *  - **El PIN no es credencial de acceso al sistema.** Identifica al operador
 *    dentro de una sesión de terminal ya autenticada. Quien lo entienda al
 *    revés terminará usando 1234 para todo.
 *  - **El de supervisor es otro.** Si coincidiera con el de sesión, cualquiera
 *    que viera teclearlo podría autorizarse un descuento sobre el tope. El
 *    servidor lo rechaza; el formulario lo dice antes.
 *
 * El PIN se escribe dos veces porque no hay forma de recuperarlo ni de
 * mostrarlo: un dedo equivocado deja al cajero afuera hasta que el supervisor
 * vuelva a ponerlo.
 */
const props = defineProps<{ employeeId: string, employeeName: string }>()

const emit = defineEmits<{ saved: [], close: [] }>()

const { t } = useI18n()
const employees = useEmployees()

const kind = ref<'session' | 'supervisor'>('session')
const pin = ref('')
const confirmation = ref('')
const error = ref('')

const kindOptions = computed(() => [
  { label: t('employees.pinSession'), value: 'session' },
  { label: t('employees.pinSupervisor'), value: 'supervisor' }
])

const digitsOnly = computed(() => /^\d{4,8}$/.test(pin.value))
const matches = computed(() => pin.value !== '' && pin.value === confirmation.value)
const valid = computed(() => digitsOnly.value && matches.value)

watch(() => props.employeeId, () => {
  pin.value = ''
  confirmation.value = ''
  error.value = ''
})

async function submit() {
  error.value = ''

  try {
    await employees.setPin(props.employeeId, pin.value, kind.value)
    pin.value = ''
    confirmation.value = ''
    emit('saved')
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}
</script>

<template>
  <form class="flex flex-col gap-3" @submit.prevent="submit">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      :title="error"
    />

    <p class="text-muted text-sm">
      {{ t('employees.pinFor', { name: props.employeeName }) }}
    </p>

    <UFormField :label="t('employees.pinKind')">
      <USelect v-model="kind" :items="kindOptions" class="w-full" />
    </UFormField>

    <p class="text-muted text-xs">
      {{ kind === 'supervisor' ? t('employees.pinSupervisorHint') : t('employees.pinSessionHint') }}
    </p>

    <UFormField :label="t('employees.pin')" required>
      <UInput
        v-model="pin"
        v-only="'digits'"
        type="password"
        inputmode="numeric"
        autocomplete="off"
        maxlength="8"
        class="w-full"
      />
    </UFormField>

    <UFormField
      :label="t('employees.pinConfirm')"
      required
      :help="t('employees.pinConfirmHint')"
    >
      <UInput
        v-model="confirmation"
        v-only="'digits'"
        type="password"
        inputmode="numeric"
        autocomplete="off"
        maxlength="8"
        class="w-full"
      />
    </UFormField>

    <p v-if="pin && !digitsOnly" class="text-error text-xs">
      {{ t('employees.pinFormat') }}
    </p>
    <p v-else-if="confirmation && !matches" class="text-error text-xs">
      {{ t('employees.pinMismatch') }}
    </p>

    <div class="flex justify-end gap-2">
      <UButton
        type="button"
        color="neutral"
        variant="ghost"
        :label="t('common.cancel')"
        @click="emit('close')"
      />
      <UButton
        type="submit"
        :disabled="!valid"
        :loading="employees.saving.value"
        :label="t('employees.savePin')"
      />
    </div>
  </form>
</template>
