import { afterEach, describe, expect, it } from 'vitest'
import { useVocabulary } from './useVocabulary'
import { useTerminal } from './useTerminal'

/**
 * El vocabulario del perfil de negocio (A-04, D-01).
 *
 * **El modelo no cambia, el nombre sí.** La cuenta abierta de un restaurante es
 * la venta suspendida de una pulpería —esa es justamente la decisión que evita
 * mantener dos modelos—, pero llamarla "venta suspendida" delante de un mesero
 * lo obliga a traducir en la cabeza cada vez.
 */

function activate(profile: 'scan_first' | 'touch_grid' | 'restaurant') {
  localStorage.setItem(
    'cherrypos.terminal.info',
    JSON.stringify({ id: 't1', code: 'CAJA-01', name: 'Caja 1', layout_profile: profile })
  )

  // El singleton ya leyó `localStorage` al cargar el módulo: se refresca el
  // estado como lo hace la activación real de la terminal.
  useTerminal().terminal.value = { id: 't1', code: 'CAJA-01', name: 'Caja 1', layout_profile: profile }
}

afterEach(() => {
  localStorage.clear()
  useTerminal().terminal.value = null
})

describe('vocabulario por perfil', () => {
  it('en retail se suspende la venta', () => {
    activate('scan_first')

    const { vt, isRestaurant } = useVocabulary()

    expect(isRestaurant.value).toBe(false)
    expect(vt('sale.suspend')).toBe('Suspender venta')
    expect(vt('sale.suspendedList')).toBe('Cuentas suspendidas')
  })

  it('en restaurante se abre una cuenta', () => {
    activate('restaurant')

    const { vt, isRestaurant } = useVocabulary()

    expect(isRestaurant.value).toBe(true)
    expect(vt('sale.suspend')).toBe('Abrir cuenta')
    expect(vt('sale.suspendedList')).toBe('Cuentas abiertas')
    expect(vt('sale.noSuspended')).toBe('No hay cuentas abiertas')
  })

  it('lo que el perfil no renombra se dice igual', () => {
    activate('restaurant')

    const { vt } = useVocabulary()

    // Cobrar es cobrar en cualquier rubro: renombrarlo por renombrar haría el
    // mapa imposible de mantener.
    expect(vt('sale.charge')).toBe('Cobrar')
  })

  it('sin terminal activada rige el perfil de mostrador', () => {
    const { profile, vt } = useVocabulary()

    expect(profile.value).toBe('scan_first')
    expect(vt('sale.suspend')).toBe('Suspender venta')
  })
})
