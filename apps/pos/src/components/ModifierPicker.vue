<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CatalogProduct } from '../composables/useCatalog'
import { amount } from '../utils/money'

/**
 * Lo que hay que preguntar antes de mandar un plato a cocina (B-06).
 *
 * El grupo **obligatorio** no deja confirmar: el servidor lo rechaza igual, pero
 * descubrirlo acá evita que el mesero vuelva a la mesa a preguntar el término de
 * la carne con el plato ya cantado.
 *
 * El precio del modificador se muestra junto a la opción porque el cliente
 * pregunta "¿cuánto sale el doble queso?" mientras el mesero teclea, no después.
 */
const props = defineProps<{ product: CatalogProduct }>()

const emit = defineEmits<{ confirm: [{ modifiers: string[], notes: string }], cancel: [] }>()

const { t } = useI18n()

const chosen = ref<Set<string>>(new Set())
const notes = ref('')

const groups = computed(() => props.product.modifier_groups ?? [])

/** Lo que falta responder. Mientras haya algo, no se puede confirmar. */
const missing = computed(() =>
  groups.value.filter((group) => countIn(group.id) < group.min_select).map((group) => group.name)
)

const extra = computed(() =>
  groups.value
    .flatMap((group) => group.modifiers)
    .filter((modifier) => chosen.value.has(modifier.id))
    .reduce((total, modifier) => total + Number.parseFloat(modifier.price_delta), 0)
    .toFixed(2)
)

watch(() => props.product.id, () => {
  chosen.value = new Set()
  notes.value = ''
}, { immediate: true })

function countIn(groupId: string): number {
  const group = groups.value.find((item) => item.id === groupId)

  return (group?.modifiers ?? []).filter((modifier) => chosen.value.has(modifier.id)).length
}

function toggle(groupId: string, modifierId: string) {
  const group = groups.value.find((item) => item.id === groupId)
  const next = new Set(chosen.value)

  if (next.has(modifierId)) {
    next.delete(modifierId)
    chosen.value = next

    return
  }

  // Un grupo de una sola respuesta —"¿término?"— **reemplaza** en vez de
  // acumular: obligar a destildar antes sería un toque de más por plato.
  if (group?.max_select === 1) {
    for (const modifier of group.modifiers) next.delete(modifier.id)
  }

  next.add(modifierId)
  chosen.value = next
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div v-for="group in groups" :key="group.id" class="flex flex-col gap-2">
      <div class="flex items-center gap-2">
        <span class="font-medium">{{ group.name }}</span>
        <UBadge
          v-if="group.min_select > 0"
          color="warning"
          variant="subtle"
          size="sm"
        >
          {{ t('modifiers.required') }}
        </UBadge>
        <span v-if="group.max_select" class="text-muted text-xs">
          {{ t('modifiers.upTo', { max: group.max_select }) }}
        </span>
      </div>

      <div class="grid gap-2 sm:grid-cols-2">
        <UButton
          v-for="modifier in group.modifiers"
          :key="modifier.id"
          :color="chosen.has(modifier.id) ? 'primary' : 'neutral'"
          :variant="chosen.has(modifier.id) ? 'solid' : 'subtle'"
          class="justify-between"
          @click="toggle(group.id, modifier.id)"
        >
          <span>{{ modifier.name }}</span>
          <span v-if="Number(modifier.price_delta) !== 0" class="pos-amount text-xs">
            +{{ amount(modifier.price_delta) }}
          </span>
        </UButton>
      </div>
    </div>

    <UFormField :label="t('modifiers.notes')" :help="t('modifiers.notesHint')">
      <UInput v-model="notes" class="w-full" />
    </UFormField>

    <p v-if="Number(extra) !== 0" class="text-sm">
      {{ t('modifiers.extra', { amount: amount(extra) }) }}
    </p>

    <p v-if="missing.length" class="text-warning text-sm">
      {{ t('modifiers.missing', { groups: missing.join(', ') }) }}
    </p>

    <div class="flex justify-end gap-2">
      <UButton
        color="neutral"
        variant="ghost"
        :label="t('common.cancel')"
        @click="emit('cancel')"
      />
      <UButton
        :disabled="missing.length > 0"
        :label="t('modifiers.add')"
        @click="emit('confirm', { modifiers: [...chosen], notes })"
      />
    </div>
  </div>
</template>
