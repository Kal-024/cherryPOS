<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PreviewLine } from '../composables/useReceiptTemplates'

/**
 * El ticket, como va a salir (H5.2).
 *
 * Antes esto era un `<pre>` que concatenaba el campo `text` de cada línea, y por
 * eso **la mitad de la plantilla no se veía**: la alineación, la negrita y el
 * tamaño viajan como campos aparte —el agente de impresión los traducirá a
 * comandos ESC/POS, no a espacios— y el logo, los separadores y los espaciadores
 * ni siquiera traen `text`. Cambiar un bloque a "centrado" no movía nada en
 * pantalla, así que la plantilla se configuraba a ciegas y el resultado se veía
 * en el primer cliente.
 *
 * Acá se dibuja línea por línea con el **mismo criterio que usa el PDF**
 * (`ReceiptPdf::line`), que es la otra salida del mismo renderizador. El ancho
 * se fija en caracteres y no en píxeles: lo que decide si un nombre largo se
 * corta son los 32 o 48 caracteres del rollo, no el tamaño de esta ventana.
 */
const props = defineProps<{ lines: PreviewLine[], width: number }>()

const { t } = useI18n()

/**
 * El ticket entero, quepa o no la ventana.
 *
 * El ancho está medido en **caracteres** —32 o 48, los del rollo— y esa cuenta
 * no se negocia: es la que decide si un nombre largo se corta. Lo que sí se
 * adapta es el cuerpo de la letra, igual que hace el PDF con los milímetros del
 * papel.
 *
 * Con un cuerpo fijo, en una columna angosta el ticket se salía por la derecha y
 * había que desplazarlo para ver los importes: se leían las etiquetas y no los
 * números, que es justo lo contrario de para qué sirve una vista previa.
 */
const AVERAGE_ADVANCE = 0.6
const MAX_PX = 13
const MIN_PX = 7

const frame = ref<HTMLElement | null>(null)
const available = ref(0)

let observer: ResizeObserver | null = null

onMounted(() => {
  if (!frame.value || typeof ResizeObserver === 'undefined') return

  observer = new ResizeObserver(([entry]) => {
    available.value = entry?.contentRect.width ?? 0
  })

  observer.observe(frame.value)
})

onBeforeUnmount(() => {
  observer?.disconnect()
  observer = null
})

const fontSize = computed(() => {
  if (available.value === 0) return MAX_PX

  const ideal = available.value / (props.width * AVERAGE_ADVANCE)

  return Math.min(MAX_PX, Math.max(MIN_PX, Math.floor(ideal * 10) / 10))
})

const style = computed(() => ({
  width: `${props.width}ch`,
  fontSize: `${fontSize.value}px`
}))

function alignOf(line: PreviewLine): string {
  if (line.align === 'center') return 'text-center'
  if (line.align === 'right') return 'text-right'

  return 'text-left'
}

/**
 * El énfasis **no agranda el cuerpo**.
 *
 * Con `lg` a 1,15 em la línea del total se ensanchaba, no entraba en los 48
 * caracteres del rollo y envolvía: el importe caía a una línea propia, debajo
 * del rótulo. En el papel salía bien y en la vista previa no, que es la peor
 * combinación posible — se configura mirando algo que no es lo que se imprime.
 *
 * Es la misma regla que sigue el PDF: el ancho está medido en caracteres, así
 * que el énfasis se hace con el grosor. El doble alto de verdad lo dará la
 * térmica, que no gasta ancho en ello.
 */
function classesOf(line: PreviewLine): string[] {
  return [
    alignOf(line),
    line.bold || line.size === 'lg' ? 'font-bold' : '',
    line.size === 'sm' ? 'text-[0.85em]' : ''
  ].filter(Boolean)
}

/** El separador ocupa el ancho del papel: es una raya, no un guion suelto. */
function separator(line: PreviewLine): string {
  return (line.char ?? '-').repeat(props.width)
}
</script>

<template>
  <div
    ref="frame"
    class="overflow-x-auto rounded-lg border border-default bg-elevated p-3 font-mono leading-tight"
  >
    <div :style="style">
      <template v-for="(line, index) in lines" :key="index">
        <!-- Sin logo cargado se marca su lugar: un hueco donde va la identidad
             del negocio se lee como una impresora rota. -->
        <div v-if="line.kind === 'logo'" class="text-center font-semibold">
          [{{ t('receipts.block.logo') }}]
        </div>

        <div v-else-if="line.kind === 'separator'" class="whitespace-pre">
          {{ separator(line) }}
        </div>

        <div v-else-if="line.kind === 'spacer'">
&nbsp;
        </div>

        <div v-else-if="line.kind === 'qr'" class="text-center text-[0.85em]">
          {{ line.value }}
        </div>

        <!-- Las líneas de dos columnas llegan ya rellenadas al ancho por el
             servidor: se respetan sus espacios o los importes dejan de cuadrar. -->
        <!-- Sin envolver: la línea ya viene justificada al ancho del papel y
             partirla movería el importe a un renglón que en el rollo no existe. -->
        <div v-else :class="classesOf(line)" class="whitespace-pre">
          {{ line.text }}
        </div>
      </template>
    </div>
  </div>
</template>
