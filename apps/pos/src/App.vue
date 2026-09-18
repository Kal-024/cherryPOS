<script setup lang="ts">
import { onMounted } from 'vue'
import { useTerminal } from './composables/useTerminal'
import { useOperator } from './composables/useOperator'

const { isActivated } = useTerminal()
const { refresh } = useOperator()

onMounted(async () => {
  // Al arrancar, la terminal puede seguir activada de ayer pero sin cajero
  // identificado: son dos credenciales independientes (D-05).
  if (isActivated.value) {
    await refresh().catch(() => undefined)
  }
})
</script>

<template>
  <UApp>
    <RouterView />
  </UApp>
</template>
