import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import EmployeePinDialog from './EmployeePinDialog.vue'

/**
 * Fijar el PIN de un empleado (D-05, P-11).
 *
 * El PIN no se puede recuperar ni mostrar, así que el error se atrapa **antes**
 * de guardarlo: un dedo equivocado deja al cajero afuera hasta que el
 * supervisor vuelva a ponerlo. Por eso se escribe dos veces y el botón no se
 * habilita hasta que coinciden.
 */

function mountDialog() {
  return mount(EmployeePinDialog, {
    props: { employeeId: 'e1', employeeName: 'Marta' }
  })
}

function inputs(wrapper: ReturnType<typeof mountDialog>) {
  return wrapper.findAll('input[type="password"]')
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('EmployeePinDialog', () => {
  it('no deja guardar hasta que los dos PIN coinciden', async () => {
    const wrapper = mountDialog()
    const [pin, confirmation] = inputs(wrapper)

    await pin!.setValue('4321')
    await confirmation!.setValue('4322')

    expect(wrapper.text()).toContain('Los dos PIN no coinciden.')

    const submit = wrapper.findAll('button').at(-1)!
    expect(submit.attributes('disabled')).toBeDefined()

    await confirmation!.setValue('4321')

    expect(submit.attributes('disabled')).toBeUndefined()
  })

  it('rechaza un PIN que no sea de cuatro a ocho dígitos', async () => {
    const wrapper = mountDialog()
    const [pin, confirmation] = inputs(wrapper)

    await pin!.setValue('12a')
    await confirmation!.setValue('12a')

    expect(wrapper.text()).toContain('Entre 4 y 8 dígitos, solo números.')
    expect(wrapper.findAll('button').at(-1)!.attributes('disabled')).toBeDefined()
  })

  it('manda el PIN y su tipo, y lo borra de la pantalla al guardar', async () => {
    const sent: Record<string, unknown>[] = []

    vi.stubGlobal('fetch', (_input: string, init: RequestInit) => {
      sent.push(JSON.parse(String(init.body)))

      return Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ message: '', data: null, status: 200 })
      } as Response)
    })

    const wrapper = mountDialog()
    const [pin, confirmation] = inputs(wrapper)

    await pin!.setValue('4321')
    await confirmation!.setValue('4321')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(sent[0]).toEqual({ pin: '4321', kind: 'session' })
    expect(wrapper.emitted('saved')).toHaveLength(1)
    // El PIN no se queda escrito en un campo que alguien pueda leer después.
    expect((inputs(wrapper)[0]!.element as HTMLInputElement).value).toBe('')
  })
})
