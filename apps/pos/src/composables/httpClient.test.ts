import { afterEach, describe, expect, it, vi } from 'vitest'
import { apiFetch, operatorSession, terminalToken } from './httpClient'

/**
 * La capa HTTP contra la API.
 *
 * Lo que se fija acá es el camino de vuelta cuando la credencial de la terminal
 * deja de servir. Pasa todos los días —el token dura veinticuatro horas— y
 * también cuando la misma terminal se activa en otro equipo, que la invalida a
 * propósito. La caja mostraba entonces el error de fábrica de Laravel,
 * "Unauthenticated.", y ninguna credencial funcionaba a partir de ahí: ni el
 * PIN correcto, porque el problema no era el PIN.
 */

function stub(status: number, payload: unknown) {
  vi.stubGlobal('fetch', () => Promise.resolve({
    ok: status < 400,
    status,
    json: () => Promise.resolve(payload)
  } as Response))
}

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  terminalToken.clear()
  operatorSession.clear()
})

describe('capa HTTP', () => {
  it('un 401 limpia la credencial y manda a activar la terminal', async () => {
    const assign = vi.fn()
    Object.defineProperty(window, 'location', {
      value: { pathname: '/', assign },
      writable: true
    })

    terminalToken.set('token-viejo')
    operatorSession.set('sesion-vieja')
    stub(401, { error: 'terminal_unauthenticated', message: 'La sesión de esta terminal venció.', status: 401 })

    await expect(apiFetch('/shifts/current')).rejects.toThrow()

    expect(terminalToken.get()).toBeNull()
    expect(operatorSession.get()).toBeNull()
    expect(assign).toHaveBeenCalledWith('/terminal')
  })

  it('en la propia pantalla de activación no se recarga en bucle', async () => {
    const assign = vi.fn()
    Object.defineProperty(window, 'location', {
      value: { pathname: '/terminal', assign },
      writable: true
    })

    stub(401, { message: 'No autenticado.', status: 401 })

    await expect(apiFetch('/shifts/current')).rejects.toThrow()

    expect(assign).not.toHaveBeenCalled()
  })

  it('un 422 no toca la credencial: es un dato mal puesto, no una sesión vencida', async () => {
    const assign = vi.fn()
    Object.defineProperty(window, 'location', { value: { pathname: '/', assign }, writable: true })

    terminalToken.set('token-bueno')
    stub(422, { message: 'Código de cajero o PIN incorrectos.', status: 422 })

    await expect(apiFetch('/operator/session', { method: 'POST' })).rejects.toThrow()

    expect(terminalToken.get()).toBe('token-bueno')
    expect(assign).not.toHaveBeenCalled()
  })
})
