import { t } from '../i18n'

/**
 * Capa HTTP contra la API de Laravel.
 *
 * Replica el contrato de red de `cherryF/src/composables/httpClient.ts` —mismas
 * cabeceras, misma forma del error, el 404 como lista vacía— en vez de
 * inventar uno que se desviaría con el tiempo. Es deliberadamente independiente
 * de `@nuxt/ui`: los avisos viven en `useApi`, aquí solo hay `fetch`.
 *
 * **Dos cabeceras propias del POS:**
 *
 *  - `Authorization` lleva el token de la **terminal**, que se autentica una vez
 *    al día (D-05).
 *  - `X-Operator-Session` dice cuál de los cajeros de esa caja está operando.
 *    No es un secreto adicional: la terminal ya está autenticada. Es la
 *    respuesta a "¿de quién es esta venta?".
 */

export const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || '/api'

const TERMINAL_TOKEN_KEY = 'cherrypos.terminal.token'
const OPERATOR_SESSION_KEY = 'cherrypos.operator.session'

export interface ApiError extends Error {
  status: number
  code?: string
  errors?: Record<string, string[]>
}

export function isApiError(err: unknown): err is ApiError {
  return err instanceof Error && 'status' in err
}

/** Mensaje legible: primero el de validación de Laravel, después el del error. */
export function firstApiErrorMessage(err: unknown, fallback?: string): string {
  if (isApiError(err) && err.errors) {
    const first = Object.values(err.errors)[0]
    if (Array.isArray(first) && first.length) return String(first[0])
  }
  if (err instanceof Error && err.message) return err.message
  // Resuelto por llamada y no como valor por defecto del parámetro: un valor
  // por defecto se evalúa al cargar el módulo y congelaría el idioma de
  // arranque.
  return fallback ?? t('common.errors.generic')
}

export const terminalToken = {
  get: (): string | null => localStorage.getItem(TERMINAL_TOKEN_KEY),
  set: (value: string) => localStorage.setItem(TERMINAL_TOKEN_KEY, value),
  clear: () => localStorage.removeItem(TERMINAL_TOKEN_KEY)
}

export const operatorSession = {
  get: (): string | null => localStorage.getItem(OPERATOR_SESSION_KEY),
  set: (value: string) => localStorage.setItem(OPERATOR_SESSION_KEY, value),
  clear: () => localStorage.removeItem(OPERATOR_SESSION_KEY)
}

function headers(base: Record<string, string> = {}): Record<string, string> {
  const result: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': document.documentElement.lang || 'es',
    ...base
  }

  const token = terminalToken.get()
  if (token) result.Authorization = `Bearer ${token}`

  const session = operatorSession.get()
  if (session) result['X-Operator-Session'] = session

  return result
}

export interface ApiEnvelope<T> {
  message: string
  data: T
  status: number
}

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  let response: Response

  try {
    response = await fetch(`${apiBaseUrl}${path}`, {
      ...init,
      headers: headers({
        // `FormData` **no** lleva Content-Type propio: el navegador tiene que
        // ponerlo él para incluir el `boundary`. Fijarlo a JSON convierte una
        // subida de archivo en un 422 imposible de explicar.
        ...(init.body && !(init.body instanceof FormData)
          ? { 'Content-Type': 'application/json' }
          : {}),
        ...(init.headers as Record<string, string> | undefined)
      })
    })
  } catch {
    // Distinguir "no hay red" de "el servidor dijo que no" importa: lo primero
    // se reintenta, lo segundo no.
    const error = new Error(t('common.errors.network')) as ApiError
    error.status = 0
    throw error
  }

  if (response.status === 204) return undefined as T

  const payload = await response.json().catch(() => null)

  if (!response.ok) {
    // El token de la terminal venció o fue reemplazado (dura 24 h, y reactivar
    // la terminal invalida el anterior a propósito). No hay nada que el cajero
    // pueda hacer desde la pantalla donde está: se limpia lo guardado y se
    // manda a activar de nuevo. Quedarse mostrando el error sería un callejón
    // sin salida en el que ninguna credencial funciona.
    if (response.status === 401) {
      terminalToken.clear()
      operatorSession.clear()

      if (typeof window !== 'undefined' && !window.location.pathname.endsWith('/terminal')) {
        // Recarga entera y no navegación del router: hay que reiniciar también
        // el estado en memoria —turno, carrito, sondeo— que quedó atado a una
        // sesión que ya no existe.
        window.location.assign('/terminal')
      }
    }

    const error = new Error(payload?.message ?? t('common.errors.generic')) as ApiError
    error.status = response.status
    if (payload?.error) error.code = payload.error
    if (payload?.errors) error.errors = payload.errors
    throw error
  }

  return (payload?.data ?? payload) as T
}

/** El 404 de la API significa "no hay nada", no "algo falló" — igual que en cherryF. */
export async function apiGetList<T>(path: string): Promise<T[]> {
  try {
    return await apiFetch<T[]>(path)
  } catch (err) {
    if (isApiError(err) && err.status === 404) return []
    throw err
  }
}

/**
 * Descarga un archivo de la API con las credenciales de la terminal.
 *
 * No pasa por `apiFetch` porque la respuesta es binaria: un `.xlsx` leído como
 * JSON se pierde entero. El `<a download>` se crea y se descarta en el acto —
 * es la única forma de nombrar el archivo desde el navegador.
 */
export async function apiDownload(path: string, filename: string): Promise<void> {
  const response = await fetch(`${apiBaseUrl}${path}`, { headers: headers() })

  if (!response.ok) {
    const payload = await response.json().catch(() => null)
    const error = new Error(payload?.message ?? t('common.errors.generic')) as ApiError
    error.status = response.status
    throw error
  }

  const blob = await response.blob()
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  link.click()

  URL.revokeObjectURL(url)
}
