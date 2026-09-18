import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import CreditPanel from './CreditPanel.vue'
import { useOperator } from '../composables/useOperator'

/**
 * La cuenta de crédito vista desde la trastienda.
 *
 * Lo que se prueba son las tres reglas cerradas de H8, no el maquetado: que un
 * cliente sin cuenta solo pueda abrirla, que el cuarto autorizado no exista
 * (G-10 fija tres) y que una cuenta bloqueada diga **por qué** lo está, porque
 * quien levanta el bloqueo merece saberlo.
 */

function envelope(data: unknown, status = 200) {
  return Promise.resolve({
    ok: status < 400,
    status,
    json: () => Promise.resolve({ message: '', data, status })
  } as Response)
}

function respond(routes: Record<string, () => Promise<Response>>) {
  vi.stubGlobal('fetch', (input: string) => {
    const match = Object.keys(routes).find((path) => String(input).includes(path))

    return match
      ? routes[match]!()
      : envelope(null, 404)
  })
}

const account = {
  id: 'a1',
  customer_id: 'c1',
  credit_limit: '5000.00',
  balance: '1200.00',
  cut_off_day: 30,
  is_blocked: false,
  blocked_reason: null
}

const person = (name: string, id: string) => ({
  id,
  relationship: 'Hijo',
  is_active: true,
  person: { full_name: name, national_id: '001-010101-0001A', phone: null }
})

beforeEach(() => {
  // El panel esconde lo que el permiso no alcanza: sin sesión no se vería
  // ningún formulario y la prueba no diría nada.
  useOperator().session.value = {
    id: 's1',
    opened_at: '',
    expires_at: '',
    employee: { id: 'e1', code: 'SUP01', full_name: 'Supervisora', discount_limit_percent: null },
    permissions: ['credit.read', 'credit.payment', 'credit.block']
  }
})

afterEach(() => {
  useOperator().session.value = null
  vi.unstubAllGlobals()
})

describe('CreditPanel', () => {
  it('sin cuenta solo ofrece abrirla', async () => {
    respond({ '/credit': () => envelope(null, 404) })

    const wrapper = mount(CreditPanel, { props: { customerId: 'c1', customerName: 'Ana' } })
    await flushPromises()

    expect(wrapper.text()).toContain('Ana no tiene cuenta de crédito.')
    expect(wrapper.text()).toContain('Abrir cuenta')
    expect(wrapper.text()).not.toContain('Registrar abono')
  })

  it('con cuenta muestra límite, saldo y disponible', async () => {
    respond({
      '/credit': () => envelope({ account, customer: { id: 'c1' }, authorized: [], available: '3800.00' })
    })

    const wrapper = mount(CreditPanel, { props: { customerId: 'c1', customerName: 'Ana' } })
    await flushPromises()

    // El disponible no es un consejo: es lo que el servidor va a dejar
    // facturar, porque el límite bloquea la venta (P-02).
    expect(wrapper.text()).toContain('Disponible')
    expect(wrapper.text()).toContain('3,800.00')
    expect(wrapper.text()).toContain('Registrar abono')
  })

  it('con tres autorizados no ofrece agregar un cuarto', async () => {
    respond({
      '/credit': () => envelope({
        account,
        customer: { id: 'c1' },
        authorized: [person('Hijo', 'p1'), person('Hija', 'p2'), person('Esposo', 'p3')],
        available: '3800.00'
      })
    })

    const wrapper = mount(CreditPanel, { props: { customerId: 'c1', customerName: 'Ana' } })
    await flushPromises()

    expect(wrapper.text()).toContain('Autorizados (3 de 3)')
    expect(wrapper.text()).toContain('Ya hay 3 autorizados, que es el máximo.')
    expect(wrapper.text()).not.toContain('Agregar autorizado')
  })

  it('una cuenta bloqueada dice por qué y solo ofrece levantarlo', async () => {
    respond({
      '/credit': () => envelope({
        account: { ...account, is_blocked: true, blocked_reason: 'Tres meses sin abonar' },
        customer: { id: 'c1' },
        authorized: [],
        available: '0.00'
      })
    })

    const wrapper = mount(CreditPanel, { props: { customerId: 'c1', customerName: 'Ana' } })
    await flushPromises()

    expect(wrapper.text()).toContain('Tres meses sin abonar')
    expect(wrapper.text()).toContain('Levantar el bloqueo')
    // El bloqueo es manual y con motivo (H8.6): no se vuelve a pedir el motivo
    // de algo ya bloqueado.
    expect(wrapper.text()).not.toContain('Motivo del bloqueo')
  })
})
