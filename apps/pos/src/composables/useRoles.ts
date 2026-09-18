import { ref, shallowRef } from 'vue'
import { apiFetch } from './httpClient'
import { isApiError } from './httpClient'

/**
 * Roles y permisos (D-13, H7).
 *
 * OSPOS asigna cada permiso persona por persona; con cuarenta empleados eso es
 * inmanejable. El rol es el grueso, y la excepción individual —que puede
 * **conceder o revocar**— vive en la ficha del empleado, no acá.
 *
 * Dos barandas que el servidor impone y la pantalla refleja para no ofrecer lo
 * imposible: `admin` no se edita —es quien puede devolver un permiso que alguien
 * se quitó por error— y un rol del sistema no se renombra ni se borra.
 */

export interface Role {
  id: string
  code: string
  name: string
  description: string | null
  is_system: boolean
  /** `admin`: no se toca desde ninguna pantalla. */
  is_protected: boolean
  employees_count: number
  permissions: string[]
}

export interface PermissionInfo {
  id: string
  code: string
  description: string | null
}

/** Permisos por módulo, tal como los agrupa el servidor. */
export type PermissionCatalog = Record<string, PermissionInfo[]>

export function useRoles() {
  const roles = shallowRef<Role[]>([])
  const catalog = ref<PermissionCatalog>({})
  const loading = ref(false)
  const saving = ref(false)

  async function load() {
    loading.value = true

    try {
      const [roleList, permissions] = await Promise.all([
        apiFetch<Role[]>('/security/roles').catch(emptyOn404<Role[]>([])),
        apiFetch<PermissionCatalog>('/security/permissions').catch(emptyOn404<PermissionCatalog>({}))
      ])

      roles.value = roleList
      catalog.value = permissions
    } finally {
      loading.value = false
    }
  }

  async function create(input: { code: string, name: string, description?: string, permissions: string[] }) {
    saving.value = true

    try {
      return await apiFetch<Role>('/security/roles', {
        method: 'POST',
        body: JSON.stringify({
          code: input.code.trim(),
          name: input.name.trim(),
          description: input.description?.trim() || null,
          permissions: input.permissions
        })
      })
    } finally {
      saving.value = false
    }
  }

  async function update(id: string, changes: { name?: string, description?: string | null, permissions?: string[] }) {
    saving.value = true

    try {
      return await apiFetch<Role>(`/security/roles/${id}`, {
        method: 'PUT',
        body: JSON.stringify(changes)
      })
    } finally {
      saving.value = false
    }
  }

  async function remove(id: string) {
    saving.value = true

    try {
      await apiFetch(`/security/roles/${id}`, { method: 'DELETE' })
    } finally {
      saving.value = false
    }
  }

  return { roles, catalog, loading, saving, load, create, update, remove }
}

/** El 404 de la API significa "no hay nada", no "algo falló". */
function emptyOn404<T>(fallback: T) {
  return (err: unknown): T => {
    if (isApiError(err) && err.status === 404) return fallback
    throw err
  }
}
