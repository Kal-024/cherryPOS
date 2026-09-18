import { computed, ref } from 'vue'
import { apiFetch, isApiError, operatorSession } from './httpClient'

/**
 * Sesión de cajero: la segunda mitad de la doble credencial (D-05).
 *
 * "Tipo abrir la calculadora únicamente cuando tenga el PIN". Un cajero cierra
 * su sesión y otro entra en el mismo equipo, **sin reautenticar la terminal**.
 */

export interface OperatorInfo {
  id: string
  code: string
  full_name: string
  /** Tope de descuento del cajero (D-02). */
  discount_limit_percent: string | null
}

export interface OperatorSessionInfo {
  id: string
  opened_at: string
  expires_at: string
  employee: OperatorInfo
  permissions: string[]
}

const session = ref<OperatorSessionInfo | null>(null)
const loading = ref(false)

export function useOperator() {
  const isSignedIn = computed(() => session.value !== null)
  const employee = computed(() => session.value?.employee ?? null)
  const permissions = computed(() => session.value?.permissions ?? [])

  function can(...codes: string[]): boolean {
    return codes.some((code) => permissions.value.includes(code))
  }

  async function refresh() {
    loading.value = true

    try {
      const data = await apiFetch<OperatorSessionInfo>('/operator/session')
      session.value = data
      operatorSession.set(data.id)
    } catch (err) {
      // 404 (nadie identificado) y 423 (hace falta PIN) no son fallos: son el
      // estado normal de una caja recién abierta.
      if (isApiError(err) && (err.status === 404 || err.status === 423)) {
        session.value = null
        operatorSession.clear()
      } else {
        throw err
      }
    } finally {
      loading.value = false
    }
  }

  async function signIn(employeeCode: string, pin: string) {
    loading.value = true

    try {
      const data = await apiFetch<OperatorSessionInfo>('/operator/session', {
        method: 'POST',
        body: JSON.stringify({ employee_code: employeeCode, pin })
      })

      session.value = data
      operatorSession.set(data.id)
    } finally {
      loading.value = false
    }
  }

  async function signOut() {
    await apiFetch('/operator/session', { method: 'DELETE' })
    session.value = null
    operatorSession.clear()
  }

  return { session, employee, permissions, loading, isSignedIn, can, refresh, signIn, signOut }
}
