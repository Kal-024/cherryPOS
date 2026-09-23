import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * Los filtros de la lista de crédito.
 *
 * Eran dos interruptores independientes y el servidor los encadena con `AND`:
 * con los dos encendidos la pantalla mostraba cuentas bloqueadas **y** con
 * saldo, que es justo lo que un rótulo que empieza con "Solo" promete que no va
 * a pasar. Dos opciones que se excluyen se piden con un control que las
 * excluya, y lo que se prueba acá es que a la API nunca le lleguen juntas.
 */

const requested: string[] = []

vi.mock('./httpClient', () => ({
  apiFetch: vi.fn(async () => ({})),
  apiGetList: vi.fn(async (path: string) => {
    requested.push(path)

    return []
  }),
  isApiError: () => false,
  firstApiErrorMessage: () => ''
}))

const { useCredit } = await import('./useCredit')

beforeEach(() => {
  requested.length = 0
})

function lastQuery(): URLSearchParams {
  return new URLSearchParams(requested.at(-1)?.split('?')[1] ?? '')
}

describe('filtros de cuentas de crédito', () => {
  it('sin filtro no manda ninguno de los dos', async () => {
    await useCredit().list({ withBalanceOnly: false, blockedOnly: false })

    expect(lastQuery().has('with_balance_only')).toBe(false)
    expect(lastQuery().has('blocked_only')).toBe(false)
  })

  it('solo con saldo manda únicamente ese', async () => {
    await useCredit().list({ withBalanceOnly: true, blockedOnly: false })

    expect(lastQuery().get('with_balance_only')).toBe('1')
    expect(lastQuery().has('blocked_only')).toBe(false)
  })

  it('solo bloqueadas manda únicamente ese', async () => {
    await useCredit().list({ withBalanceOnly: false, blockedOnly: true })

    expect(lastQuery().get('blocked_only')).toBe('1')
    expect(lastQuery().has('with_balance_only')).toBe(false)
  })
})
