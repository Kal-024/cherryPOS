<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useOperator } from '../../composables/useOperator'

/**
 * Portada de la trastienda.
 *
 * No es decorativa: es la lista de lo que **este** operador puede hacer. Un
 * cajero que entre por curiosidad ve poco o nada; el encargado ve su día.
 */
// Sin permiso declarado a propósito: la portada no muestra nada por sí misma,
// solo los accesos que el permiso del operador habilita. Exigir uno concreto
// dejaría fuera a quien administre clientes pero no catálogo.
definePage({ meta: { layout: 'admin' } })

const { t } = useI18n()
const { can } = useOperator()

const sections = computed(() =>
  [
    {
      to: '/admin/productos',
      title: t('admin.sections.products'),
      description: t('admin.sections.productsHint'),
      icon: 'i-lucide-package',
      permissions: ['catalog.product.read']
    },
    {
      to: '/admin/clientes',
      title: t('admin.sections.customers'),
      description: t('admin.sections.customersHint'),
      icon: 'i-lucide-users',
      permissions: ['customer.read']
    },
    {
      to: '/admin/credito',
      title: t('admin.sections.credit'),
      description: t('admin.sections.creditHint'),
      icon: 'i-lucide-credit-card',
      permissions: ['credit.read']
    },
    {
      to: '/admin/gastos',
      title: t('admin.sections.expenses'),
      description: t('admin.sections.expensesHint'),
      icon: 'i-lucide-receipt',
      permissions: ['expense.read']
    },
    {
      to: '/admin/inventario',
      title: t('admin.sections.inventory'),
      description: t('admin.sections.inventoryHint'),
      icon: 'i-lucide-boxes',
      permissions: ['inventory.read']
    },
    {
      to: '/admin/comprobantes',
      title: t('admin.sections.receipts'),
      description: t('admin.sections.receiptsHint'),
      icon: 'i-lucide-printer',
      permissions: ['pos_settings.read']
    },
    {
      to: '/admin/importacion',
      title: t('admin.sections.imports'),
      description: t('admin.sections.importsHint'),
      icon: 'i-lucide-file-spreadsheet',
      permissions: ['catalog.import']
    },
    {
      to: '/admin/reportes',
      title: t('admin.sections.reports'),
      description: t('admin.sections.reportsHint'),
      icon: 'i-lucide-bar-chart-3',
      permissions: ['report.daily_sales']
    },
    {
      to: '/admin/auditoria',
      title: t('admin.sections.audit'),
      description: t('admin.sections.auditHint'),
      icon: 'i-lucide-scroll-text',
      permissions: ['report.audit_log']
    },
    {
      to: '/admin/empleados',
      title: t('admin.sections.employees'),
      description: t('admin.sections.employeesHint'),
      icon: 'i-lucide-id-card',
      permissions: ['employee.read']
    },
    {
      to: '/admin/roles',
      title: t('admin.sections.roles'),
      description: t('admin.sections.rolesHint'),
      icon: 'i-lucide-shield',
      permissions: ['role.manage']
    },
    {
      to: '/admin/configuracion',
      title: t('admin.sections.settings'),
      description: t('admin.sections.settingsHint'),
      icon: 'i-lucide-settings-2',
      permissions: ['pos_settings.read']
    },
    {
      to: '/admin/erp',
      title: t('admin.sections.erp'),
      description: t('admin.sections.erpHint'),
      icon: 'i-lucide-upload-cloud',
      permissions: ['erp_outbox.read']
    }
  ].filter((section) => can(...section.permissions))
)
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <UCard
      v-for="section in sections"
      :key="section.to"
      class="transition hover:ring-primary"
      :ui="{ body: 'p-4' }"
    >
      <RouterLink :to="section.to" class="flex items-start gap-3">
        <UIcon :name="section.icon" class="text-primary mt-0.5 size-5 shrink-0" />
        <span>
          <span class="block font-medium">{{ section.title }}</span>
          <span class="text-muted block text-sm">{{ section.description }}</span>
        </span>
      </RouterLink>
    </UCard>
  </div>
</template>
