<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../composables/httpClient'
import { useOffline } from '../composables/useOffline'
import { useTerminal } from '../composables/useTerminal'

/**
 * Activación de la terminal (D-05).
 *
 * Se hace una vez al día y la ve un encargado, no un cajero en hora pico: aquí
 * sí hay lugar para credenciales completas.
 */
definePage({ meta: { layout: false } })

const { t } = useI18n()
const router = useRouter()
const { activate, loading } = useTerminal()

const branchCode = ref('')
const terminalCode = ref('')
const secret = ref('')
const error = ref('')

/**
 * Lo que otras terminales dejaron sin enviar en este equipo (H6.4).
 *
 * Un ticket vendido sin conexión solo puede mandarlo **su** caja: el servidor lo
 * atribuye por el token y su número sale de la serie reservada a ella. Como
 * cambiar de terminal ya no borra nada, esta lista es la que evita que una caja
 * con plata sin registrar quede olvidada en un rincón del navegador.
 */
const offline = useOffline()
const pendientes = ref<Array<{ terminal_id: string, count: number }>>([])

onMounted(async () => {
  pendientes.value = await offline.pendingByTerminal().catch(() => [])
})

async function submit() {
  error.value = ''

  try {
    await activate(branchCode.value, terminalCode.value, secret.value)
    await router.push('/login')
  } catch (err) {
    error.value = firstApiErrorMessage(err)
  }
}
</script>

<template>
  <div class="flex h-full items-center justify-center p-6">
    <UCard class="w-full max-w-sm">
      <template #header>
        <h1 class="text-lg font-semibold">
          {{ t('terminal.title') }}
        </h1>
        <p class="text-muted text-sm">
          {{ t('terminal.subtitle') }}
        </p>
      </template>

      <form class="flex flex-col gap-3" @submit.prevent="submit">
        <UFormField :label="t('terminal.branchCode')">
          <UInput v-model="branchCode" autofocus autocomplete="off" />
        </UFormField>

        <UFormField :label="t('terminal.terminalCode')">
          <UInput v-model="terminalCode" autocomplete="off" />
        </UFormField>

        <UFormField :label="t('terminal.secret')">
          <UInput v-model="secret" type="password" autocomplete="off" />
        </UFormField>

        <!-- Errores con acción, nunca callejones sin salida. -->
        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          :description="error"
        />

        <UButton type="submit" block :loading="loading">
          {{ t('terminal.submit') }}
        </UButton>
      </form>

      <template v-if="pendientes.length > 0" #footer>
        <p class="text-muted text-sm">
          {{ t('terminal.pendingHere') }}
        </p>

        <ul class="mt-1 text-sm">
          <li v-for="fila in pendientes" :key="fila.terminal_id" class="flex items-center gap-2">
            <UIcon name="i-lucide-clock" class="text-warning size-4" />
            {{ t('terminal.pendingRow', { count: fila.count }) }}
          </li>
        </ul>
      </template>
    </UCard>
  </div>
</template>
