<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../composables/httpClient'
import { useOffline } from '../composables/useOffline'
import { useOperator } from '../composables/useOperator'
import { useTerminal } from '../composables/useTerminal'

/**
 * Ingreso del cajero con PIN.
 *
 * Es la pantalla del relevo: el cajero anterior salió y el siguiente entra en
 * la misma terminal, sin volver a autenticar el equipo. Cada segundo cuenta —
 * foco en el primer campo y envío con Enter.
 */
definePage({ meta: { layout: false } })

const { t } = useI18n()
const router = useRouter()
const { signIn, loading } = useOperator()
const { terminal, branch, deactivate } = useTerminal()
const offline = useOffline()

const employeeCode = ref('')
const pin = ref('')
const error = ref('')

/**
 * Cambiar de terminal en este equipo.
 *
 * La activación es de la terminal, no del navegador, y hasta ahora no había
 * forma de deshacerla: activado como mostrador, el mismo equipo no podía entrar
 * como salón salvo borrando los datos del navegador a mano. Y hace falta más de
 * lo que parece — el equipo de repuesto que entra como la caja que se rompió, la
 * tablet que de día es salón y de noche mostrador.
 *
 * Va acá y no en la cabecera de la caja a propósito: esta es la pantalla del
 * relevo, entre un cajero y el siguiente, y volver a activar exige el secreto
 * del equipo, que el cajero no tiene.
 */
const changing = ref(false)

/**
 * Lo que queda esperando en esta caja.
 *
 * Los tickets vendidos sin conexión llevan **correlativos reservados de esta
 * terminal**, y el servidor los atribuye según el token con el que se envían:
 * solo puede mandarlos ella. No impide cambiar —la bandeja queda guardada bajo
 * su dueño y sale sola al volver a activarla— pero hay que decirlo: es plata
 * registrada que el servidor todavía no vio.
 */
const pending = computed(() => offline.pending.value.length)

async function confirmChange() {
  changing.value = false

  await deactivate()

  /*
   * Recarga entera, no navegación del router.
   *
   * Los composables del POS son singletons de módulo —el carrito, el turno, el
   * sondeo, el modo degradado— y quedarían en memoria con el estado de la
   * terminal que se acaba de dejar: la caja del salón abriría con las líneas
   * que el mostrador tenía a medias. Arrancar de nuevo es la única forma
   * honesta de decir "este equipo ahora es otra caja".
   */
  window.location.assign('/terminal')
}

async function submit() {
  error.value = ''

  try {
    await signIn(employeeCode.value, pin.value)
    await router.push('/')
  } catch (err) {
    error.value = firstApiErrorMessage(err)
    pin.value = ''
  }
}
</script>

<template>
  <div class="flex h-full items-center justify-center p-6">
    <UCard class="w-full max-w-sm">
      <template #header>
        <h1 class="text-lg font-semibold">
          {{ t('operator.title') }}
        </h1>
        <p class="text-muted text-sm">
          {{ t('operator.subtitle', { terminal: terminal?.code ?? '', branch: branch?.name ?? '' }) }}
        </p>
      </template>

      <form class="flex flex-col gap-3" @submit.prevent="submit">
        <UFormField :label="t('operator.employeeCode')">
          <UInput
            v-model="employeeCode"
            v-only="'code'"
            autofocus
            autocomplete="off"
          />
        </UFormField>

        <UFormField :label="t('operator.pin')">
          <UInput
            v-model="pin"
            v-only="'digits'"
            type="password"
            inputmode="numeric"
            autocomplete="off"
            maxlength="12"
          />
        </UFormField>

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          :description="error"
        />

        <UButton type="submit" block :loading="loading">
          {{ t('operator.submit') }}
        </UButton>
      </form>

      <template #footer>
        <UButton
          color="neutral"
          variant="link"
          size="sm"
          icon="i-lucide-monitor-cog"
          :label="t('terminal.change')"
          @click="changing = true"
        />
      </template>
    </UCard>
    <!--
      Confirmación, porque volver atrás cuesta: reactivar exige el código y el
      secreto del equipo, y quien está en esta pantalla puede no tenerlos.
    -->
    <UModal v-model:open="changing" :title="t('terminal.change')">
      <template #body>
        <p class="text-sm">
          {{ t('terminal.changeHint', { terminal: terminal?.code ?? '' }) }}
        </p>

        <UAlert
          v-if="pending > 0"
          class="mt-3"
          color="warning"
          variant="subtle"
          :description="t('terminal.changePending', { count: pending, terminal: terminal?.code ?? '' })"
        />
      </template>

      <template #footer>
        <UButton
          color="neutral"
          variant="subtle"
          :label="t('common.cancel')"
          @click="changing = false"
        />
        <UButton
          color="error"
          :label="t('terminal.changeConfirm')"
          @click="confirmChange"
        />
      </template>
    </UModal>
  </div>
</template>
