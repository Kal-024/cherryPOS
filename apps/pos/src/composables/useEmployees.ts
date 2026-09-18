import { ref, shallowRef } from 'vue'
import { apiFetch, apiGetList } from './httpClient'

/**
 * Empleados (D-05, D-02, D-03, D-13).
 *
 * La ficha reúne lo que ninguna otra pantalla puede dar: el **PIN** —segunda
 * mitad de la doble credencial—, el **PIN de supervisor**, que es otro (P-11),
 * los topes propios de cada cajero y las excepciones individuales de permisos.
 *
 * Del PIN nunca vuelve nada: la API responde `has_pin`, y con eso alcanza para
 * dibujar la pantalla. Pedirle al servidor el PIN para "mostrarlo al
 * supervisor" sería justamente lo que un PIN no admite.
 */

export interface PermissionOverride {
  code: string
  /** `deny` gana sobre el rol: es la excepción configurada a mano. */
  effect: 'grant' | 'deny'
}

export interface Employee {
  id: string
  code: string
  name: string
  national_id: string | null
  phone: string | null
  email: string | null
  discount_limit_percent: string | null
  temp_item_daily_limit: number | null
  is_active: boolean
  has_pin: boolean
  has_supervisor_pin: boolean
  pin_locked: boolean
  roles: string[]
  overrides?: PermissionOverride[]
  effective_permissions?: string[]
}

export interface EmployeeDraft {
  name: string
  code: string
  national_id: string
  phone: string
  email: string
  discount_limit_percent: string
  temp_item_daily_limit: string
  roles: string[]
  is_active: boolean
}

export function emptyEmployeeDraft(): EmployeeDraft {
  return {
    name: '',
    code: '',
    national_id: '',
    phone: '',
    email: '',
    // Vacío = rige el tope del rol (D-02) y el límite por defecto de ítems
    // temporales (D-03). No es lo mismo que cero, que sería "ninguno".
    discount_limit_percent: '',
    temp_item_daily_limit: '',
    roles: [],
    is_active: true
  }
}

export function employeeDraftOf(employee: Employee): EmployeeDraft {
  return {
    name: employee.name,
    code: employee.code,
    national_id: employee.national_id ?? '',
    phone: employee.phone ?? '',
    email: employee.email ?? '',
    discount_limit_percent: employee.discount_limit_percent ?? '',
    temp_item_daily_limit: employee.temp_item_daily_limit?.toString() ?? '',
    roles: [...employee.roles],
    is_active: employee.is_active
  }
}

export function useEmployees() {
  const employees = shallowRef<Employee[]>([])
  const loading = ref(false)
  const saving = ref(false)

  async function list(includeInactive = false) {
    loading.value = true

    try {
      const query = includeInactive ? '?include_inactive=1' : ''
      employees.value = await apiGetList<Employee>(`/security/employees${query}`)
    } finally {
      loading.value = false
    }
  }

  async function find(id: string): Promise<Employee> {
    return apiFetch<Employee>(`/security/employees/${id}`)
  }

  async function create(draft: EmployeeDraft): Promise<Employee> {
    saving.value = true

    try {
      return await apiFetch<Employee>('/security/employees', {
        method: 'POST',
        body: JSON.stringify(payload(draft))
      })
    } finally {
      saving.value = false
    }
  }

  async function update(id: string, draft: EmployeeDraft): Promise<Employee> {
    saving.value = true

    try {
      return await apiFetch<Employee>(`/security/employees/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload(draft))
      })
    } finally {
      saving.value = false
    }
  }

  /** La lista completa, no lo agregado: lo que se manda es el estado final. */
  async function saveOverrides(id: string, overrides: PermissionOverride[]): Promise<Employee> {
    saving.value = true

    try {
      return await apiFetch<Employee>(`/security/employees/${id}/overrides`, {
        method: 'PUT',
        body: JSON.stringify({ overrides })
      })
    } finally {
      saving.value = false
    }
  }

  async function setPin(id: string, pin: string, kind: 'session' | 'supervisor') {
    saving.value = true

    try {
      await apiFetch(`/security/employees/${id}/pin`, {
        method: 'POST',
        body: JSON.stringify({ pin, kind })
      })
    } finally {
      saving.value = false
    }
  }

  /** Levanta el bloqueo por intentos fallidos (D-05, B-15). */
  async function unlock(id: string) {
    saving.value = true

    try {
      await apiFetch(`/security/employees/${id}/unlock`, { method: 'POST' })
    } finally {
      saving.value = false
    }
  }

  return { employees, loading, saving, list, find, create, update, saveOverrides, setPin, unlock }
}

function payload(draft: EmployeeDraft): Record<string, unknown> {
  const blank = (value: string) => (value.trim() === '' ? null : value.trim())

  return {
    name: draft.name.trim(),
    code: draft.code.trim(),
    national_id: blank(draft.national_id),
    phone: blank(draft.phone),
    email: blank(draft.email),
    discount_limit_percent: blank(draft.discount_limit_percent),
    temp_item_daily_limit: blank(draft.temp_item_daily_limit),
    roles: draft.roles,
    is_active: draft.is_active
  }
}
