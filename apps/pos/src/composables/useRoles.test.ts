import { afterEach, describe, expect, it, vi } from 'vitest'
import { useRoles } from './useRoles'

/**
 * Lo que el terminal manda al administrar roles (D-13, H7).
 *
 * La pantalla arma conjuntos de códigos; lo que viaja tiene que ser la lista
 * completa de permisos del rol, porque el servidor **sincroniza**: mandar solo
 * los agregados dejaría los quitados en su sitio y nadie entendería por qué el
 * cajero sigue pudiendo anular.
 */

function stub(responses: Record<string, unknown>, status = 200) {
  const sent: { url: string, method?: string, body: Record<string, unknown> }[] = []

  vi.stubGlobal('fetch', (input: string, init: RequestInit = {}) => {
    const url = String(input)
    sent.push({ url, method: init.method, body: init.body ? JSON.parse(String(init.body)) : {} })

    const match = Object.keys(responses).find((path) => url.includes(path))

    return Promise.resolve({
      ok: status < 400,
      status: match ? status : 404,
      json: () => Promise.resolve({ message: '', data: match ? responses[match] : null, status })
    } as Response)
  })

  return sent
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('administración de roles', () => {
  it('guardar manda la lista completa de permisos', async () => {
    const sent = stub({ '/security/roles/': { id: 'r1' } })

    await useRoles().update('r1', { name: 'Bodeguero', permissions: ['inventory.read', 'inventory.receive'] })

    expect(sent[0]!.method).toBe('PUT')
    expect(sent[0]!.body.permissions).toEqual(['inventory.read', 'inventory.receive'])
  })

  it('un rol nuevo viaja con su código y su descripción vacía como null', async () => {
    const sent = stub({ '/security/roles': { id: 'r2' } }, 201)

    await useRoles().create({ code: ' bodeguero ', name: ' Bodeguero ', permissions: ['inventory.read'] })

    expect(sent[0]!.body.code).toBe('bodeguero')
    expect(sent[0]!.body.name).toBe('Bodeguero')
    expect(sent[0]!.body.description).toBeNull()
  })

  it('sin roles ni permisos cargados la pantalla no revienta', async () => {
    // 404 significa "no hay nada", no "algo falló": es el mismo contrato que
    // usa el resto del cliente HTTP.
    stub({}, 404)

    const roles = useRoles()
    await roles.load()

    expect(roles.roles.value).toEqual([])
    expect(roles.catalog.value).toEqual({})
  })
})
