<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useOperator } from '../composables/useOperator'

/**
 * Trastienda: catálogo, clientes, crédito, gastos y configuración.
 *
 * Es la otra mitad del sistema y tiene otras reglas que la caja. Allí el cajero
 * no toca el ratón y la densidad manda; aquí el supervisor edita con calma, de
 * pie frente al mismo equipo o desde otro. Por eso navegación lateral visible
 * en vez de atajos, y por eso una salida a la caja siempre a la vista: quien
 * entra a corregir un precio vuelve a cobrar en seguida.
 *
 * El menú muestra **solo lo que el permiso alcanza** (H7). Un ítem que lleva a
 * un 403 es un callejón sin salida, justo lo que la regla de interfaz prohíbe.
 */
const { t } = useI18n()
const { can, employee } = useOperator()

interface AdminLink {
  to: string
  label: string
  icon: string
  permissions: string[]
}

const links = computed<AdminLink[]>(() =>
  ([
    {
      to: '/admin/productos',
      label: t('admin.sections.products'),
      icon: 'i-lucide-package',
      permissions: ['catalog.product.read']
    },
    {
      to: '/admin/clientes',
      label: t('admin.sections.customers'),
      icon: 'i-lucide-users',
      permissions: ['customer.read']
    },
    {
      to: '/admin/credito',
      label: t('admin.sections.credit'),
      icon: 'i-lucide-credit-card',
      permissions: ['credit.read']
    },
    {
      to: '/admin/gastos',
      label: t('admin.sections.expenses'),
      icon: 'i-lucide-receipt',
      permissions: ['expense.read']
    },
    {
      to: '/admin/inventario',
      label: t('admin.sections.inventory'),
      icon: 'i-lucide-boxes',
      permissions: ['inventory.read']
    },
    {
      to: '/admin/comprobantes',
      label: t('admin.sections.receipts'),
      icon: 'i-lucide-printer',
      permissions: ['pos_settings.read']
    },
    {
      to: '/admin/importacion',
      label: t('admin.sections.imports'),
      icon: 'i-lucide-file-spreadsheet',
      permissions: ['catalog.import']
    },
    {
      to: '/admin/reportes',
      label: t('admin.sections.reports'),
      icon: 'i-lucide-bar-chart-3',
      permissions: ['report.daily_sales']
    },
    {
      to: '/admin/auditoria',
      label: t('admin.sections.audit'),
      icon: 'i-lucide-scroll-text',
      permissions: ['report.audit_log']
    },
    {
      to: '/admin/empleados',
      label: t('admin.sections.employees'),
      icon: 'i-lucide-id-card',
      permissions: ['employee.read']
    },
    {
      to: '/admin/roles',
      label: t('admin.sections.roles'),
      icon: 'i-lucide-shield',
      permissions: ['role.manage']
    },
    {
      to: '/admin/configuracion',
      label: t('admin.sections.settings'),
      icon: 'i-lucide-settings-2',
      permissions: ['pos_settings.read']
    },
    {
      to: '/admin/erp',
      label: t('admin.sections.erp'),
      icon: 'i-lucide-upload-cloud',
      permissions: ['erp_outbox.read']
    }
  ] satisfies AdminLink[]).filter((link) => can(...link.permissions))
)
</script>

<template>
  <div class="flex h-full flex-col">
    <header class="flex items-center gap-3 border-b border-default px-3 py-2">
      <UButton
        to="/"
        icon="i-lucide-arrow-left"
        color="neutral"
        variant="ghost"
        :label="$t('admin.backToSale')"
      />

      <span class="font-semibold">{{ $t('admin.title') }}</span>

      <div class="grow" />

      <span v-if="employee" class="text-muted text-sm">{{ employee.full_name }}</span>
    </header>

    <div class="flex min-h-0 grow">
      <nav class="w-56 shrink-0 border-r border-default p-2">
        <UButton
          v-for="link in links"
          :key="link.to"
          :to="link.to"
          :icon="link.icon"
          :label="link.label"
          color="neutral"
          variant="ghost"
          class="w-full justify-start"
          active-class="bg-elevated"
        />
      </nav>

      <main class="min-h-0 grow overflow-auto p-4">
        <RouterView />
      </main>
    </div>
  </div>
</template>
