import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { Customer } from '../composables/useCustomers'

/**
 * A quién se le vende (Q-03).
 *
 * En caja **solo se usan clientes ya registrados**: crear es del supervisor, y
 * con el ERP presente la creación pasa allá. Lo que se prueba acá es que el
 * selector no ofrezca ningún camino para dar de alta, y que diga de un vistazo
 * lo que el cajero necesita saber antes de fiar.
 */

const listed: Customer[] = [
  {
    id: 'c1',
    name: 'Rosa Mendoza',
    kind: 'account',
    code: null,
    is_tax_exempt: false,
    tax_exempt_reference: null,
    discount_percent: null,
    consent_email: false,
    consent_whatsapp: false,
    is_active: true,
    person: {
      full_name: 'Rosa Mendoza',
      national_id: '001-010180-0001X',
      tax_id: null,
      email: null,
      phone: null,
      whatsapp: null,
      address: null
    },
    credit_account: {
      id: 'a1',
      credit_limit: '5000.00',
      balance: '1500.00',
      cut_off_day: 15,
      is_blocked: false
    } as Customer['credit_account']
  },
  {
    id: 'c2',
    name: 'Carlos Ruiz',
    kind: 'account',
    code: null,
    is_tax_exempt: true,
    tax_exempt_reference: 'EX-1',
    discount_percent: null,
    consent_email: false,
    consent_whatsapp: false,
    is_active: true,
    person: null,
    credit_account: {
      id: 'a2',
      credit_limit: '1000.00',
      balance: '0.00',
      cut_off_day: 15,
      is_blocked: true,
      blocked_reason: 'Mora'
    } as Customer['credit_account']
  }
]

const list = vi.fn(async () => undefined)

vi.mock('../composables/useCustomers', async (original) => ({
  ...(await original<typeof import('../composables/useCustomers')>()),
  useCustomers: () => ({
    customers: { value: listed },
    loading: { value: false },
    saving: { value: false },
    list,
    find: vi.fn(),
    create: vi.fn(),
    update: vi.fn()
  })
}))

const CustomerPicker = (await import('./CustomerPicker.vue')).default

function open() {
  return mount(CustomerPicker, {
    props: { open: true, selected: null },
    global: {
      stubs: {
        UModal: { template: '<div><slot name="body" /><slot name="footer" /></div>' },
        UInput: { template: '<input />' },
        UAlert: { template: '<div><slot /></div>' },
        UBadge: { template: '<span><slot /></span>' },
        UButton: {
          props: ['label'],
          template: '<button @click="$emit(\'click\')">{{ label }}<slot /></button>'
        }
      }
    }
  })
}

describe('CustomerPicker', () => {
  it('no ofrece crear un cliente', () => {
    // Crearlos es del supervisor: ofrecerlo acá llenaría el padrón de
    // duplicados tecleados con un cliente delante y prisa.
    const text = open().text()

    expect(text).not.toContain('Nuevo')
    expect(text).not.toContain('Crear')
  })

  it('dice cuánto crédito queda disponible', () => {
    // Límite 5.000 con 1.500 de saldo: 3.500. Es lo que decide si la venta se
    // puede cerrar a crédito, y saberlo después de cargar el carrito es tarde.
    expect(open().text()).toContain('3500.00')
  })

  it('marca la cuenta bloqueada', () => {
    expect(open().text()).toContain('Bloqueada')
  })

  it('al elegir emite el cliente y se cierra', async () => {
    const wrapper = open()

    await wrapper.findAll('button')[0]?.trigger('click')

    expect(wrapper.emitted('choose')?.[0]).toEqual([listed[0]])
    expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
  })

  it('con cliente puesto deja quitarlo', async () => {
    const wrapper = mount(CustomerPicker, {
      props: { open: true, selected: listed[0]! },
      global: {
        stubs: {
          UModal: { template: '<div><slot name="footer" /></div>' },
          UButton: {
            props: ['label'],
            template: '<button @click="$emit(\'click\')">{{ label }}</button>'
          }
        }
      }
    })

    await nextTick()
    // Equivocarse de cliente delante del cliente es lo más fácil del mundo.
    await wrapper.findAll('button')[0]?.trigger('click')

    expect(wrapper.emitted('choose')?.[0]).toEqual([null])
  })
})
