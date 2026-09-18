import { computed } from 'vue'
import { t } from '../i18n'
import { useTerminal } from './useTerminal'

/**
 * El vocabulario del perfil de negocio (A-04, D-01).
 *
 * **La misma venta se llama distinto según el local.** Suspender una venta en
 * una pulpería es dejarla para después; en un restaurante es *abrir una cuenta*,
 * y llamarla "venta suspendida" delante de un mesero obliga a traducir en la
 * cabeza cada vez. El modelo de datos no cambia —la cuenta abierta **es** la
 * venta suspendida, y esa es justamente la decisión que evita dos modelos—;
 * cambia solo cómo se nombra en pantalla.
 *
 * Por eso esto no es un diccionario paralelo: es un mapa de **clave a clave**
 * dentro del mismo catálogo i18n. Un texto nuevo sigue entrando por el catálogo
 * y sigue teniendo su traducción al inglés; lo único que el perfil decide es
 * cuál de las dos claves se muestra.
 */

/** Clave genérica → clave del perfil. Lo que no esté acá se dice igual. */
const BY_PROFILE: Record<string, Record<string, string>> = {
  restaurant: {
    'sale.suspend': 'sale.openTab',
    'sale.suspended': 'sale.tabOpen',
    'sale.resume': 'sale.reopenTab',
    'sale.suspendedList': 'sale.tabsList',
    'sale.noSuspended': 'sale.noTabs',
    'sale.label': 'sale.tableLabel',
    'sale.labelHint': 'sale.tableLabelHint',
    'shortcuts.suspend': 'sale.openTab',
    'shortcuts.suspended': 'sale.tabsList'
  }
}

export function useVocabulary() {
  // El `t` del módulo, no el del componente: así el vocabulario también sirve
  // fuera de una pantalla —en un aviso, en un comprobante— sin arrastrar el
  // contexto de inyección de Vue.
  const { layoutProfile } = useTerminal()

  const profile = computed(() => layoutProfile.value ?? 'scan_first')
  const isRestaurant = computed(() => profile.value === 'restaurant')

  /** Traduce con el nombre que usa este local. */
  function vt(key: string, named?: Record<string, unknown>): string {
    const mapped = BY_PROFILE[profile.value]?.[key] ?? key

    return named ? t(mapped, named) : t(mapped)
  }

  return { profile, isRestaurant, vt }
}
