import { afterEach, describe, expect, it, vi } from 'vitest'
import { useSupervision } from './useSupervision'
import { useOperator } from './useOperator'

/**
 * La bandeja de avisos al supervisor (P-11).
 *
 * Dos reglas: que **solo** la consulte quien tiene el permiso —al cajero de
 * turno una campana que no puede abrir le agrega ruido— y que un fallo del
 * sondeo no haga nada visible, porque sin red la caja sigue vendiendo y los
 * avisos esperan.
 */

function signIn(permissions: string[]) {
  useOperator().session.value = {
    id: 's1',
    opened_at: '',
    expires_at: '',
    employee: { id: 'e1', code: 'SUP01', full_name: 'Supervisora', discount_limit_percent: null },
    permissions
  }
}

afterEach(() => {
  useOperator().session.value = null
  useSupervision().stop()
  vi.unstubAllGlobals()
})

describe('avisos al supervisor', () => {
  it('el cajero sin permiso no consulta la bandeja', async () => {
    signIn(['pos_sale.create'])

    const calls: string[] = []
    vi.stubGlobal('fetch', (input: string) => {
      calls.push(String(input))

      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: [] }) } as Response)
    })

    await useSupervision().refresh()

    expect(calls).toHaveLength(0)
  })

  it('el supervisor recibe los no leídos y puede marcarlos', async () => {
    signIn(['supervisor.notifications'])

    const notification = {
      id: 'n1',
      event: 'temporary_item',
      severity: 'warning',
      title: 'Ítem temporal',
      body: 'CAJ01 vendió un ítem temporal',
      context: null,
      occurred_at: '2026-09-16T10:00:00Z',
      read_at: null
    }

    vi.stubGlobal('fetch', (_input: string, init: RequestInit = {}) =>
      Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({
          message: '',
          data: init.method === 'POST' ? null : [notification],
          status: 200
        })
      } as Response))

    const supervision = useSupervision()
    await supervision.refresh()

    expect(supervision.unread.value).toHaveLength(1)

    await supervision.markRead('n1')

    // Marcado deja de ocupar la campana: la bandeja muestra lo pendiente, no el
    // histórico.
    expect(supervision.unread.value).toHaveLength(0)
  })

  it('sin red la bandeja queda como estaba y no rompe la caja', async () => {
    signIn(['supervisor.notifications'])

    vi.stubGlobal('fetch', () => Promise.reject(new Error('sin red')))

    const supervision = useSupervision()

    // `start` traga el fallo a propósito: un aviso que no llegó no puede
    // aparecer como un error delante de un cliente.
    expect(() => supervision.start()).not.toThrow()
    await expect(supervision.refresh()).rejects.toThrow()
    expect(supervision.unread.value).toEqual([])
  })
})
