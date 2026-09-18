<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useOperator } from '../composables/useOperator'
import { useTerminal } from '../composables/useTerminal'
import { useConnection } from '../composables/useConnection'
import { useShift } from '../composables/useShift'
import { useOffline } from '../composables/useOffline'
import { useSync } from '../composables/useSync'
import SupervisorInbox from '../components/SupervisorInbox.vue'

const route = useRoute()
const router = useRouter()

/**
 * Dónde está parado el cajero.
 *
 * La cabecera es la misma en la caja y en el salón, así que sin esto no había
 * forma de volver: el botón de ida existía y el de vuelta no, y salir de la
 * sesión era el único camino de regreso.
 */
const inRegister = computed(() => route.path === '/')

const { terminal, branch } = useTerminal()
const { employee, can, signOut } = useOperator()

/**
 * El relevo (D-05): sale el cajero, la terminal **sigue autenticada**.
 *
 * Hay que llevar a la pantalla del PIN a mano. El guardia de ruta solo corre al
 * navegar, así que cerrar la sesión sin moverse dejaba la caja dibujada con el
 * cajero ya cerrado: el siguiente no tenía dónde identificarse y la única
 * salida visible era recargar, que además lleva a reactivar la terminal si el
 * navegador se limpió. Es exactamente el callejón sin salida que la regla de
 * interfaz prohíbe.
 */
async function endShiftSession() {
  await signOut()
  await router.push('/login')
}
const { label: connectionLabel, color: connectionColor } = useConnection()
const shift = useShift()
const sync = useSync()
const offline = useOffline()
</script>

<template>
  <div class="flex h-full flex-col">
    <header class="flex items-center gap-3 border-b border-default px-3 py-2">
      <span class="font-semibold">{{ branch?.name }}</span>
      <span class="text-muted text-sm">{{ terminal?.code }}</span>

      <div class="grow" />

      <!-- El turno abierto, visible: nada se registra fuera de uno (H4.1). -->
      <UBadge v-if="shift.code.value" color="neutral" variant="subtle">
        {{ shift.code.value }}
      </UBadge>

      <!-- Cerrar el turno es de fin de jornada y de quien tiene el permiso: un
           botón discreto, no uno al lado de "Cobrar". -->
      <UButton
        v-if="shift.isOpen.value && can('pos_shift.close')"
        icon="i-lucide-lock"
        color="neutral"
        variant="ghost"
        :aria-label="$t('shiftUi.close')"
        @click="shift.closing.value = true"
      />

      <!--
        Estado de conexión siempre visible: el cajero debe poder responder
        "¿esto se guardó?" sin preguntarle a nadie.

        El sondeo tiene su propia lectura: la red del navegador puede estar bien
        y el servidor de la sucursal no contestar.
      -->
      <UBadge
        :color="sync.reachable.value ? connectionColor : 'error'"
        variant="subtle"
      >
        {{ sync.reachable.value ? connectionLabel : $t('connection.degraded') }}
      </UBadge>

      <!-- Cuántos tickets faltan enviar (H6.4). El cajero tiene que poder
           responder "¿esto se guardó?" sin preguntarle a nadie. -->
      <UBadge
        v-if="offline.pending.value.length > 0"
        color="warning"
        variant="subtle"
      >
        {{ $t('connection.pending', { count: offline.pending.value.length }) }}
      </UBadge>

      <!--
        Ida y vuelta entre la caja y el salón: son la misma aplicación y el
        mesero salta de una a otro todo el turno. El botón dice **a dónde va**,
        no dónde se está, y por eso desaparece cuando ya se está ahí.
      -->
      <UButton
        v-if="!inRegister"
        to="/"
        icon="i-lucide-calculator"
        color="neutral"
        variant="ghost"
        :label="$t('nav.register')"
      />

      <UButton
        v-if="can('dining.read') && inRegister"
        to="/salon"
        icon="i-lucide-layout-grid"
        color="neutral"
        variant="ghost"
        :aria-label="$t('admin.sections.dining')"
      />

      <!-- Avisos al supervisor (P-11): bandeja con contador, nunca un diálogo.
           La caja no se detiene por un aviso. -->
      <SupervisorInbox />

      <!-- Entrada a la trastienda. Solo para quien tiene con qué: al cajero de
           turno no le sirve un botón que lleva a un 403. -->
      <UButton
        v-if="can('catalog.product.create', 'catalog.product.update', 'customer.create', 'customer.update', 'credit.read', 'expense.create', 'inventory.adjust', 'role.manage', 'catalog.import', 'employee.manage', 'report.daily_sales')"
        to="/admin"
        icon="i-lucide-settings"
        color="neutral"
        variant="ghost"
        :aria-label="$t('admin.open')"
      />

      <template v-if="employee">
        <span class="text-sm">{{ employee.full_name }}</span>
        <UButton
          icon="i-lucide-log-out"
          color="neutral"
          variant="ghost"
          :aria-label="$t('operator.signOut')"
          @click="endShiftSession"
        />
      </template>
    </header>

    <!-- La página va como ruta hija, no como ranura: el plugin de layouts anida
         la ruta y el contenido llega por `RouterView`. Con `<slot />` se dibuja
         la cabecera y el área de trabajo queda en blanco. -->
    <main class="min-h-0 grow">
      <RouterView />
    </main>
  </div>
</template>
