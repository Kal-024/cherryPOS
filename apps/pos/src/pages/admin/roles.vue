<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import { type Role, useRoles } from '../../composables/useRoles'
import { useOperator } from '../../composables/useOperator'

/**
 * Roles y permisos (D-13, H7.1).
 *
 * El rol es el grueso y la excepción individual vive en la ficha del empleado:
 * mezclarlas acá llevaría de vuelta al modelo de OSPOS, donde cada permiso se
 * asigna persona por persona y con cuarenta empleados nadie sabe quién puede
 * qué.
 *
 * La pantalla muestra las barandas en vez de dejar que el servidor las explique
 * después: `admin` aparece bloqueado —es quien puede devolver un permiso que
 * alguien se quitó por error, y sin internet no hay a quién llamar—, un rol del
 * sistema tiene el nombre fijo, y uno con gente asignada no ofrece borrarse.
 */
definePage({ meta: { layout: 'admin', permission: 'role.manage' } })

const { t } = useI18n()
const roles = useRoles()
const { can, refresh: refreshOperator } = useOperator()
const toast = useToast()

const selected = ref<Role | null>(null)
const draftName = ref('')
const draftDescription = ref('')
const draftPermissions = ref<Set<string>>(new Set())
const creating = ref(false)
const draftCode = ref('')
const formError = ref('')

const canManage = computed(() => can('role.manage'))
const modules = computed(() => Object.keys(roles.catalog.value).sort())

const editable = computed(() => canManage.value && selected.value !== null && !selected.value.is_protected)
const nameEditable = computed(() => editable.value && selected.value?.is_system !== true)
const deletable = computed(() =>
  editable.value && selected.value?.is_system === false && selected.value.employees_count === 0
)

const dirty = computed(() => {
  if (!selected.value) return false

  const current = new Set(selected.value.permissions)

  return (
    draftName.value !== selected.value.name
    || (draftDescription.value || null) !== selected.value.description
    || current.size !== draftPermissions.value.size
    || [...draftPermissions.value].some((code) => !current.has(code))
  )
})

onMounted(async () => {
  try {
    await roles.load()
    const first = roles.roles.value[0]
    if (first) select(first)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
})

function select(role: Role) {
  creating.value = false
  formError.value = ''
  selected.value = role
  draftName.value = role.name
  draftDescription.value = role.description ?? ''
  draftPermissions.value = new Set(role.permissions)
}

function startCreate() {
  creating.value = true
  formError.value = ''
  selected.value = null
  draftCode.value = ''
  draftName.value = ''
  draftDescription.value = ''
  draftPermissions.value = new Set()
}

function toggle(code: string, checked: boolean) {
  const next = new Set(draftPermissions.value)

  if (checked) next.add(code)
  else next.delete(code)

  draftPermissions.value = next
}

function toggleModule(module: string, checked: boolean) {
  const next = new Set(draftPermissions.value)

  for (const permission of roles.catalog.value[module] ?? []) {
    if (checked) next.add(permission.code)
    else next.delete(permission.code)
  }

  draftPermissions.value = next
}

function moduleState(module: string): boolean {
  const permissions = roles.catalog.value[module] ?? []

  return permissions.length > 0 && permissions.every((permission) => draftPermissions.value.has(permission.code))
}

async function save() {
  formError.value = ''

  try {
    if (creating.value) {
      const created = await roles.create({
        code: draftCode.value,
        name: draftName.value,
        description: draftDescription.value,
        permissions: [...draftPermissions.value]
      })

      await roles.load()
      select(roles.roles.value.find((role) => role.id === created.id) ?? created)
      toast.add({ title: t('roles.created'), color: 'success' })

      return
    }

    if (!selected.value) return

    await roles.update(selected.value.id, {
      ...(nameEditable.value ? { name: draftName.value, description: draftDescription.value || null } : {}),
      permissions: [...draftPermissions.value]
    })

    await roles.load()
    const fresh = roles.roles.value.find((role) => role.id === selected.value?.id)
    if (fresh) select(fresh)

    // Quien acaba de cambiar permisos puede haberse cambiado los propios: sin
    // releer la sesión, el menú seguiría ofreciendo lo que ya no alcanza.
    await refreshOperator().catch(() => undefined)

    toast.add({ title: t('roles.saved'), color: 'success' })
  } catch (err) {
    formError.value = firstApiErrorMessage(err)
  }
}

async function remove() {
  if (!selected.value) return

  try {
    await roles.remove(selected.value.id)
    toast.add({ title: t('roles.deleted'), color: 'success' })
    selected.value = null
    await roles.load()
    const first = roles.roles.value[0]
    if (first) select(first)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}
</script>

<template>
  <div class="grid min-h-0 gap-4 lg:grid-cols-[16rem_1fr]">
    <div class="flex flex-col gap-1">
      <UButton
        v-for="role in roles.roles.value"
        :key="role.id"
        :color="selected?.id === role.id ? 'primary' : 'neutral'"
        :variant="selected?.id === role.id ? 'solid' : 'ghost'"
        class="justify-start"
        @click="select(role)"
      >
        <span class="flex w-full items-center gap-2">
          <span class="grow text-left">{{ role.name }}</span>
          <UBadge color="neutral" variant="subtle" size="sm">
            {{ role.employees_count }}
          </UBadge>
        </span>
      </UButton>

      <UButton
        v-if="canManage"
        icon="i-lucide-plus"
        color="neutral"
        variant="subtle"
        class="mt-2 justify-start"
        :label="t('roles.new')"
        @click="startCreate"
      />
    </div>

    <div v-if="selected || creating" class="flex min-h-0 flex-col gap-3">
      <UAlert
        v-if="formError"
        color="error"
        variant="subtle"
        :title="formError"
      />

      <UAlert
        v-if="selected?.is_protected"
        color="info"
        variant="subtle"
        :title="t('roles.protected')"
        :description="t('roles.protectedHint')"
      />

      <div class="flex flex-wrap items-end gap-2">
        <UFormField
          v-if="creating"
          :label="t('roles.code')"
          required
          :help="t('roles.codeHint')"
        >
          <UInput v-model="draftCode" class="w-40" />
        </UFormField>

        <UFormField :label="t('roles.name')" :help="!nameEditable && !creating ? t('roles.nameFixed') : undefined">
          <UInput v-model="draftName" :disabled="!creating && !nameEditable" class="w-56" />
        </UFormField>

        <UFormField :label="t('roles.description')" class="grow">
          <UInput v-model="draftDescription" :disabled="!creating && !nameEditable" class="w-full" />
        </UFormField>

        <UButton
          v-if="canManage && (creating || editable)"
          icon="i-lucide-save"
          :disabled="!creating && !dirty"
          :loading="roles.saving.value"
          :label="creating ? t('roles.create') : t('roles.save')"
          @click="save"
        />

        <UButton
          v-if="deletable"
          icon="i-lucide-trash-2"
          color="error"
          variant="subtle"
          :loading="roles.saving.value"
          :label="t('roles.delete')"
          @click="remove"
        />
      </div>

      <p v-if="selected && selected.employees_count > 0" class="text-muted text-xs">
        {{ t('roles.inUse', { count: selected.employees_count }) }}
      </p>

      <div class="flex flex-col gap-3 overflow-y-auto">
        <div v-for="module in modules" :key="module" class="rounded-lg border border-default p-2">
          <div class="mb-2 flex items-center gap-2">
            <span class="font-medium">{{ t(`roles.module.${module}`) }}</span>
            <div class="grow" />
            <USwitch
              :model-value="moduleState(module)"
              :disabled="!creating && !editable"
              :label="t('roles.wholeModule')"
              @update:model-value="(value: boolean) => toggleModule(module, value)"
            />
          </div>

          <div class="grid gap-1 sm:grid-cols-2">
            <USwitch
              v-for="permission in roles.catalog.value[module]"
              :key="permission.code"
              :model-value="draftPermissions.has(permission.code)"
              :disabled="!creating && !editable"
              :label="permission.description ?? permission.code"
              :description="permission.code"
              @update:model-value="(value: boolean) => toggle(permission.code, value)"
            />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
