<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { firstApiErrorMessage } from '../../composables/httpClient'
import {
  type Employee,
  type EmployeeDraft,
  type PermissionOverride,
  emptyEmployeeDraft,
  employeeDraftOf,
  useEmployees
} from '../../composables/useEmployees'
import { useRoles } from '../../composables/useRoles'
import { useOperator } from '../../composables/useOperator'
import EmployeePinDialog from '../../components/EmployeePinDialog.vue'

/**
 * Empleados (D-05, D-02, D-03, D-13, H7).
 *
 * Es la única pantalla donde vive la **excepción individual**: conceder o
 * revocar un permiso a una persona concreta. Sin ella, quitarle el descuento a
 * un cajero obligaría a inventarle un rol propio, que es el modelo de OSPOS que
 * el proyecto dejó atrás.
 *
 * El PIN se fija acá y **nunca se muestra**: la API responde si hay PIN puesto,
 * no cuál es. Lo que sí se ve es el bloqueo por intentos fallidos, porque el
 * cajero bloqueado está parado frente a la caja esperando que alguien lo
 * levante.
 */
definePage({ meta: { layout: 'admin', permission: 'employee.read' } })

const { t } = useI18n()
const employees = useEmployees()
const roles = useRoles()
const { can, refresh: refreshOperator } = useOperator()
const toast = useToast()

const includeInactive = ref(false)
const selected = ref<Employee | null>(null)
const draft = ref<EmployeeDraft>(emptyEmployeeDraft())
const overrides = ref<Map<string, 'grant' | 'deny'>>(new Map())
const creating = ref(false)
const formError = ref('')
const pinOpen = ref(false)

const canManage = computed(() => can('employee.manage'))
const modules = computed(() => Object.keys(roles.catalog.value).sort())

const roleOptions = computed(() =>
  roles.roles.value.map((role) => ({ label: role.name, value: role.code }))
)

/** Lo que los roles elegidos conceden, antes de aplicar las excepciones. */
const fromRoles = computed(() => {
  const codes = new Set<string>()

  for (const role of roles.roles.value) {
    if (!draft.value.roles.includes(role.code)) continue
    for (const code of role.permissions) codes.add(code)
  }

  return codes
})

onMounted(async () => {
  try {
    await Promise.all([employees.list(includeInactive.value), roles.load()])
    const first = employees.employees.value[0]
    if (first) await select(first)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
})

async function refresh() {
  try {
    await employees.list(includeInactive.value)
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function select(employee: Employee) {
  creating.value = false
  formError.value = ''

  try {
    const full = await employees.find(employee.id)
    selected.value = full
    draft.value = employeeDraftOf(full)
    overrides.value = new Map((full.overrides ?? []).map((item) => [item.code, item.effect]))
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

function startCreate() {
  creating.value = true
  formError.value = ''
  selected.value = null
  draft.value = emptyEmployeeDraft()
  overrides.value = new Map()
}

/**
 * Tres estados por permiso, y los tres significan algo distinto: lo que da el
 * rol, la concesión a mano y la revocación a mano.
 */
function cycle(code: string) {
  const next = new Map(overrides.value)
  const current = next.get(code)

  if (current === undefined) next.set(code, fromRoles.value.has(code) ? 'deny' : 'grant')
  else if (current === 'grant') next.set(code, 'deny')
  else next.delete(code)

  overrides.value = next
}

function stateOf(code: string): 'role' | 'grant' | 'deny' | 'none' {
  const override = overrides.value.get(code)

  if (override) return override

  return fromRoles.value.has(code) ? 'role' : 'none'
}

async function save() {
  formError.value = ''

  try {
    const saved = creating.value
      ? await employees.create(draft.value)
      : await employees.update(selected.value!.id, draft.value)

    const list = [...overrides.value].map(([code, effect]) => ({ code, effect }) as PermissionOverride)
    const fresh = await employees.saveOverrides(saved.id, list)

    await refresh()
    selected.value = fresh
    draft.value = employeeDraftOf(fresh)
    overrides.value = new Map((fresh.overrides ?? []).map((item) => [item.code, item.effect]))
    creating.value = false

    // Quien edita su propia ficha puede haberse quitado un permiso: sin releer
    // la sesión, el menú seguiría ofreciendo lo que ya no alcanza.
    await refreshOperator().catch(() => undefined)

    toast.add({ title: t('employees.saved'), color: 'success' })
  } catch (err) {
    formError.value = firstApiErrorMessage(err)
  }
}

async function unlock() {
  if (!selected.value) return

  try {
    await employees.unlock(selected.value.id)
    await select(selected.value)
    await refresh()
    toast.add({ title: t('employees.unlocked'), color: 'success' })
  } catch (err) {
    toast.add({ title: firstApiErrorMessage(err), color: 'error' })
  }
}

async function onPinSaved() {
  pinOpen.value = false
  toast.add({ title: t('employees.pinSaved'), color: 'success' })

  if (selected.value) await select(selected.value)
}
</script>

<template>
  <div class="grid min-h-0 gap-4 lg:grid-cols-[18rem_1fr]">
    <div class="flex flex-col gap-1">
      <USwitch
        v-model="includeInactive"
        :label="t('employees.includeInactive')"
        class="mb-2"
        @update:model-value="refresh"
      />

      <UButton
        v-for="employee in employees.employees.value"
        :key="employee.id"
        :color="selected?.id === employee.id ? 'primary' : 'neutral'"
        :variant="selected?.id === employee.id ? 'solid' : 'ghost'"
        class="justify-start"
        @click="select(employee)"
      >
        <span class="flex w-full items-center gap-2">
          <span class="grow text-left">
            {{ employee.name }}
            <span class="text-muted text-xs">{{ employee.code }}</span>
          </span>
          <UIcon v-if="employee.pin_locked" name="i-lucide-lock" class="text-error size-4" />
          <UIcon v-else-if="!employee.has_pin" name="i-lucide-key-round" class="text-muted size-4" />
        </span>
      </UButton>

      <UButton
        v-if="canManage"
        icon="i-lucide-user-plus"
        color="neutral"
        variant="subtle"
        class="mt-2 justify-start"
        :label="t('employees.new')"
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

      <!-- El cajero bloqueado está parado frente a la caja: el aviso va arriba
           y con la acción al lado. -->
      <UAlert
        v-if="selected?.pin_locked"
        color="error"
        variant="subtle"
        :title="t('employees.locked')"
        :description="t('employees.lockedHint')"
      >
        <template #actions>
          <UButton
            v-if="canManage"
            color="error"
            variant="solid"
            size="xs"
            :loading="employees.saving.value"
            :label="t('employees.unlock')"
            @click="unlock"
          />
        </template>
      </UAlert>

      <div class="grid gap-3 sm:grid-cols-2">
        <UFormField :label="t('employees.name')" required>
          <UInput v-model="draft.name" :disabled="!canManage" class="w-full" />
        </UFormField>

        <UFormField :label="t('employees.code')" required :help="t('employees.codeHint')">
          <UInput v-model="draft.code" :disabled="!canManage" class="w-full" />
        </UFormField>

        <UFormField :label="t('employees.nationalId')">
          <UInput v-model="draft.national_id" :disabled="!canManage" class="w-full" />
        </UFormField>

        <UFormField :label="t('employees.phone')">
          <UInput v-model="draft.phone" :disabled="!canManage" class="w-full" />
        </UFormField>

        <UFormField :label="t('employees.discountLimit')" :help="t('employees.discountLimitHint')">
          <UInput
            v-model="draft.discount_limit_percent"
            inputmode="decimal"
            :disabled="!canManage"
            class="w-full"
          />
        </UFormField>

        <UFormField :label="t('employees.tempItemLimit')" :help="t('employees.tempItemLimitHint')">
          <UInput
            v-model="draft.temp_item_daily_limit"
            inputmode="numeric"
            :disabled="!canManage"
            class="w-full"
          />
        </UFormField>

        <UFormField :label="t('employees.roles')" class="sm:col-span-2">
          <USelectMenu
            v-model="draft.roles"
            :items="roleOptions"
            value-key="value"
            multiple
            :disabled="!canManage"
            class="w-full"
          />
        </UFormField>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <USwitch v-model="draft.is_active" :label="t('employees.active')" :disabled="!canManage" />

        <div class="grow" />

        <UBadge v-if="selected?.has_supervisor_pin" color="neutral" variant="subtle">
          {{ t('employees.hasSupervisorPin') }}
        </UBadge>

        <UButton
          v-if="canManage && selected"
          icon="i-lucide-key-round"
          color="neutral"
          variant="subtle"
          :label="selected.has_pin ? t('employees.changePin') : t('employees.setPin')"
          @click="pinOpen = true"
        />

        <UButton
          v-if="canManage"
          icon="i-lucide-save"
          :loading="employees.saving.value"
          :label="creating ? t('employees.create') : t('employees.save')"
          @click="save"
        />
      </div>

      <!-- Las excepciones individuales (D-13). Tres estados, y los tres dicen
           algo distinto: lo que da el rol, lo concedido a mano y lo revocado. -->
      <div class="flex flex-col gap-2 overflow-y-auto">
        <p class="text-sm font-medium">
          {{ t('employees.overrides') }}
        </p>
        <p class="text-muted text-xs">
          {{ t('employees.overridesHint') }}
        </p>

        <div v-for="module in modules" :key="module" class="rounded-lg border border-default p-2">
          <p class="mb-2 font-medium">
            {{ t(`roles.module.${module}`) }}
          </p>

          <div class="grid gap-1 sm:grid-cols-2">
            <button
              v-for="permission in roles.catalog.value[module]"
              :key="permission.code"
              type="button"
              class="flex items-center gap-2 rounded-md px-2 py-1 text-left text-sm hover:bg-elevated"
              :disabled="!canManage"
              @click="cycle(permission.code)"
            >
              <UIcon
                :name="stateOf(permission.code) === 'deny'
                  ? 'i-lucide-x-circle'
                  : stateOf(permission.code) === 'none' ? 'i-lucide-circle' : 'i-lucide-check-circle'"
                class="size-4 shrink-0"
                :class="{
                  'text-error': stateOf(permission.code) === 'deny',
                  'text-primary': stateOf(permission.code) === 'grant',
                  'text-muted': stateOf(permission.code) === 'none' || stateOf(permission.code) === 'role'
                }"
              />
              <span class="grow">{{ permission.description ?? permission.code }}</span>
              <UBadge
                v-if="stateOf(permission.code) === 'grant' || stateOf(permission.code) === 'deny'"
                :color="stateOf(permission.code) === 'deny' ? 'error' : 'primary'"
                variant="subtle"
                size="sm"
              >
                {{ t(`employees.state.${stateOf(permission.code)}`) }}
              </UBadge>
              <UBadge
                v-else-if="stateOf(permission.code) === 'role'"
                color="neutral"
                variant="subtle"
                size="sm"
              >
                {{ t('employees.state.role') }}
              </UBadge>
            </button>
          </div>
        </div>
      </div>
    </div>

    <UModal v-model:open="pinOpen" :title="t('employees.pinTitle')">
      <template #body>
        <EmployeePinDialog
          v-if="selected"
          :employee-id="selected.id"
          :employee-name="selected.name"
          @saved="onPinSaved"
          @close="pinOpen = false"
        />
      </template>
    </UModal>
  </div>
</template>
