import { computed, ref } from 'vue'
import { apiFetch, operatorSession, terminalToken } from './httpClient'
import { STORES, clear, put } from '../db/idb'
import { useOffline } from './useOffline'

/**
 * Estado de la terminal: la primera mitad de la doble credencial (D-05).
 *
 * Singleton de módulo, como `useAuth` en `cherryF`. No es un store: las páginas
 * siguen siendo dueñas de su propio estado.
 */

export interface TerminalInfo {
  id: string
  code: string
  name: string
  layout_profile: 'scan_first' | 'touch_grid' | 'restaurant' | null
}

export interface BranchInfo {
  id: string
  code: string
  name: string
  timezone: string
  tax_id: string | null
}

const TERMINAL_KEY = 'cherrypos.terminal.info'
const BRANCH_KEY = 'cherrypos.branch.info'

function restore<T>(key: string): T | null {
  const raw = localStorage.getItem(key)
  return raw ? (JSON.parse(raw) as T) : null
}

const terminal = ref<TerminalInfo | null>(restore<TerminalInfo>(TERMINAL_KEY))
const branch = ref<BranchInfo | null>(restore<BranchInfo>(BRANCH_KEY))
const loading = ref(false)

export function useTerminal() {
  const isActivated = computed(() => terminalToken.get() !== null && terminal.value !== null)

  /** Perfil de pantalla efectivo (A-04): manda el de la terminal sobre el del negocio. */
  const layoutProfile = computed(() => terminal.value?.layout_profile ?? 'scan_first')

  async function activate(branchCode: string, terminalCode: string, secret: string) {
    loading.value = true

    try {
      const data = await apiFetch<{
        token: string
        expires_at: string
        terminal: TerminalInfo
        branch: BranchInfo
      }>('/terminal/login', {
        method: 'POST',
        body: JSON.stringify({
          branch_code: branchCode,
          terminal_code: terminalCode,
          secret
        })
      })

      const previousBranch = branch.value?.id ?? null

      terminalToken.set(data.token)
      terminal.value = data.terminal
      branch.value = data.branch
      localStorage.setItem(TERMINAL_KEY, JSON.stringify(data.terminal))
      localStorage.setItem(BRANCH_KEY, JSON.stringify(data.branch))

      // El catálogo es de la **sucursal**: dos cajas del mismo local lo
      // comparten y cambiar entre ellas no debe volver a bajar tres mil
      // productos. Si el equipo pasó a otra sucursal, en cambio, lo cacheado ya
      // no es de nadie.
      if (previousBranch !== null && previousBranch !== data.branch.id) {
        await clear(STORES.products)
        await put(STORES.meta, 'catalog', { synced_at: null, branch_id: data.branch.id })
      }

      // Lo que quedó de antes de que el estado tuviera dueño se adopta bajo esta
      // terminal: son tickets que representan plata ya cobrada.
      await useOffline().adoptLegacy(data.terminal.id).catch(() => 0)
    } finally {
      loading.value = false
    }
  }

  async function deactivate() {
    try {
      await apiFetch('/terminal/logout', { method: 'POST' })
    } catch {
      // Desactivar localmente aunque el servidor no conteste: si no, una
      // terminal sin red quedaría atrapada con una sesión que no puede cerrar.
    }

    // Primero se apaga el reintento: la credencial se está borrando y seguir
    // mandando tickets de la terminal que se abandona solo produce rechazos.
    useOffline().stopAutoFlush()

    terminalToken.clear()
    operatorSession.clear()
    terminal.value = null
    branch.value = null
    localStorage.removeItem(TERMINAL_KEY)
    localStorage.removeItem(BRANCH_KEY)

    // **Lo guardado no se toca.** La venta a medias, la bandeja de tickets sin
    // enviar y el bloque de correlativos llevan el dueño en la clave: quedan
    // esperando a que esa terminal vuelva a activarse en este equipo. Lo único
    // que se olvida es la credencial — volver exige código y secreto, que es lo
    // que hace que un equipo perdido deje de servir.
  }

  return { terminal, branch, loading, isActivated, layoutProfile, activate, deactivate }
}
