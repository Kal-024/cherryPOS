<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SaleLine } from '../composables/useCart'
import { amount, quantity } from '../utils/money'

/**
 * Las líneas del carrito.
 *
 * **Densidad alta**: una pantalla de caja muestra información, no aire. La línea
 * recién agregada queda siempre visible abajo, porque es la que el cajero acaba
 * de pasar y la que va a querer corregir si se equivocó.
 */
const props = defineProps<{
  lines: SaleLine[]
  busy?: boolean
  courses?: boolean
  /** La línea sobre la que actúan los atajos. Se dibuja destacada. */
  activeId?: string | null
}>()

const emit = defineEmits<{
  remove: [string],
  edit: [SaleLine],
  course: [SaleLine, number],
  select: [string],
  qty: [string, string]
}>()

const { t } = useI18n()

/**
 * Edición de cantidad en el lugar.
 *
 * Corregir la cantidad es la operación más frecuente de una caja después de
 * agregar, y hasta ahora no existía: había que quitar la línea y volver a
 * pasarla. Se edita **sobre la propia línea** —no en un diálogo— porque el
 * cajero está mirando ahí y porque un diálogo obliga a confirmar dos veces.
 *
 * El campo se abre con el valor en blanco a propósito: quien toca la cantidad
 * casi siempre viene a teclear otra, no a editar la que hay.
 */
const editing = ref<string | null>(null)
const draft = ref('')
// Referencia por función y no por nombre: dentro de un `v-for`, un `ref` con
// nombre devuelve un arreglo, y enfocar `[0]` cuando solo una fila se dibuja es
// una forma rebuscada de decir lo mismo.
const input = ref<HTMLInputElement | null>(null)

function startEdit(line: SaleLine) {
  emit('select', line.id)
  editing.value = line.id
  draft.value = ''

  void nextTick(() => input.value?.focus())
}

function commit(line: SaleLine) {
  const value = draft.value.trim().replace(',', '.')

  editing.value = null

  if (value === '' || Number(value) <= 0 || !Number.isFinite(Number(value))) return

  emit('qty', line.id, value)
}

function cancel() {
  editing.value = null
  draft.value = ''
}

// Si la línea activa cambia por teclado, la edición abierta deja de tener
// sentido: se estaría escribiendo sobre una línea que ya no es la que se mira.
watch(() => props.activeId, () => {
  editing.value = null
})

defineExpose({ startEdit })

/**
 * Los tres primeros cursos tienen nombre; del cuarto en adelante se numeran.
 *
 * Es lo que hace el mesero al cantarlos, y nombrar nueve cursos sería inventar
 * una carta que el local no tiene (G-16).
 */
function courseName(course: number): string {
  return course <= 3 ? t(`kitchen.course${course}`) : t('kitchen.courseN', { course })
}

const courseOptions = [1, 2, 3].map((course) => ({ label: courseName(course), value: course }))
</script>

<template>
  <div class="flex h-full flex-col overflow-hidden rounded-lg border border-default">
    <div
      v-if="lines.length === 0"
      class="flex grow flex-col items-center justify-center gap-1 p-8 text-center text-muted"
    >
      <UIcon name="i-lucide-shopping-cart" class="size-8" />
      <p class="font-medium">
        {{ t('sale.empty') }}
      </p>
      <p class="text-sm">
        {{ t('sale.emptyHint') }}
      </p>
    </div>

    <div v-else class="min-h-0 grow overflow-y-auto">
      <table class="w-full text-sm">
        <thead class="sticky top-0 bg-elevated text-muted">
          <tr class="text-left">
            <th class="w-10 px-2 py-1 font-medium">
              #
            </th>
            <th class="px-2 py-1 font-medium">
              {{ t('sale.lines') }}
            </th>
            <th v-if="courses" class="w-28 px-2 py-1 font-medium">
              {{ t('sale.course') }}
            </th>
            <th class="w-20 px-2 py-1 text-right font-medium">
              {{ t('sale.qty') }}
            </th>
            <th class="w-28 px-2 py-1 text-right font-medium">
              {{ t('sale.price') }}
            </th>
            <th class="w-28 px-2 py-1 text-right font-medium">
              {{ t('sale.lineTotal') }}
            </th>
            <th class="w-10" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="line in lines"
            :key="line.id"
            class="border-t border-default hover:bg-elevated/60"
            :class="line.id === activeId ? 'bg-primary/10' : ''"
            @click="emit('select', line.id)"
          >
            <td class="px-2 py-1 text-muted">
              {{ line.sequence }}
            </td>
            <td class="px-2 py-1">
              <span>{{ line.description }}</span>
              <!-- La marca del combo explica por qué esa línea trae descuento. -->
              <UBadge
                v-if="line.group_name"
                color="neutral"
                variant="subtle"
                size="sm"
                class="ml-2"
              >
                {{ line.group_name }}
              </UBadge>
              <span
                v-if="Number(line.line_discount) + Number(line.sale_discount_share) > 0"
                class="text-muted ml-2 text-xs"
              >
                −{{ amount(String(Number(line.line_discount) + Number(line.sale_discount_share))) }}
              </span>
            </td>
            <!--
              El curso se corrige acá y no al agregar el plato: "el postre va
              después" se dice en la mesa, mientras el mesero relee lo pedido.
            -->
            <td v-if="courses" class="px-2 py-1">
              <USelect
                :model-value="line.course ?? 1"
                :items="courseOptions"
                size="xs"
                :disabled="busy"
                :aria-label="t('sale.course')"
                @update:model-value="(value: number) => emit('course', line, value)"
              />
            </td>
            <!--
              La cantidad es lo que más se corrige en una caja: se toca y se
              escribe. Sin esto había que quitar la línea y volver a pasarla.
            -->
            <td class="px-2 py-1 text-right">
              <input
                v-if="editing === line.id"
                :ref="(el) => { input = el as HTMLInputElement | null }"
                v-model="draft"
                type="text"
                inputmode="decimal"
                :placeholder="quantity(line.qty)"
                class="pos-amount w-16 rounded border border-primary bg-default px-1 text-right outline-none"
                :aria-label="t('sale.qty')"
                @keydown.enter.prevent="commit(line)"
                @keydown.esc.prevent="cancel"
                @blur="commit(line)"
                @click.stop
              >
              <button
                v-else
                type="button"
                class="pos-amount w-16 rounded px-1 text-right hover:bg-elevated"
                :disabled="busy"
                :title="t('sale.qtyEdit')"
                @click.stop="startEdit(line)"
              >
                {{ quantity(line.qty) }}
              </button>
            </td>
            <td class="pos-amount px-2 py-1 text-right">
              {{ amount(line.unit_price) }}
            </td>
            <td class="pos-amount px-2 py-1 text-right font-medium">
              {{ amount(line.total) }}
            </td>
            <td class="px-1 py-1 text-right">
              <UButton
                icon="i-lucide-x"
                color="neutral"
                variant="ghost"
                size="xs"
                :disabled="busy"
                :aria-label="t('sale.remove')"
                @click="emit('remove', line.id)"
              />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
