<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupervision } from '../composables/useSupervision'

/**
 * El aviso al supervisor, en la caja (P-11).
 *
 * Es una campana con un contador, no un diálogo: **la caja no se detiene**. El
 * supervisor lee cuando puede, y lo que exige acción inmediata —autorizar un
 * descuento sobre el tope— es otra cosa y ocurre en el momento, con PIN.
 *
 * Solo aparece para quien tiene el permiso: al cajero de turno una campana que
 * no puede abrir le ocupa espacio y le agrega ruido.
 */
const { t } = useI18n()
const supervision = useSupervision()

onMounted(() => supervision.start())
onUnmounted(() => supervision.stop())
</script>

<template>
  <UPopover v-if="supervision.unread.value.length > 0">
    <UButton
      icon="i-lucide-bell"
      :color="supervision.hasCritical.value ? 'error' : 'warning'"
      variant="ghost"
      :aria-label="t('supervision.title')"
    >
      <UBadge :color="supervision.hasCritical.value ? 'error' : 'warning'" variant="subtle" size="sm">
        {{ supervision.unread.value.length }}
      </UBadge>
    </UButton>

    <template #content>
      <div class="flex max-h-96 w-96 flex-col gap-2 overflow-y-auto p-2">
        <p class="text-muted text-xs">
          {{ t('supervision.hint') }}
        </p>

        <div
          v-for="item in supervision.unread.value"
          :key="item.id"
          class="flex flex-col gap-1 rounded-lg border border-default p-2 text-sm"
        >
          <div class="flex items-center gap-2">
            <UBadge
              :color="item.severity === 'critical' ? 'error' : item.severity === 'warning' ? 'warning' : 'neutral'"
              variant="subtle"
              size="sm"
            >
              {{ t(`supervision.severity.${item.severity}`) }}
            </UBadge>
            <span class="font-medium">{{ item.title }}</span>
            <div class="grow" />
            <UButton
              icon="i-lucide-check"
              color="neutral"
              variant="ghost"
              size="xs"
              :aria-label="t('supervision.markRead')"
              @click="supervision.markRead(item.id)"
            />
          </div>

          <p v-if="item.body" class="text-muted">
            {{ item.body }}
          </p>
        </div>
      </div>
    </template>
  </UPopover>
</template>
