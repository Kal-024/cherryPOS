import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SpecialLineDialog from './SpecialLineDialog.vue'

/**
 * Las válvulas de escape del catálogo (D-03).
 *
 * El control vivía entero en el servidor y no tenía pantalla: el cajero que se
 * topaba con algo sin cargar lo cobraba sobre otro producto, y el inventario
 * quedaba mintiendo sin que nada lo denunciara.
 *
 * Lo que se prueba acá son las dos cosas que la pantalla tiene que resolver bien:
 * que **las dos formas manden lo que corresponde** —el producto lleva cantidad y
 * precio, el monto no—, y que **el cupo se vea antes** en vez de descubrirse con
 * el cliente delante.
 */

const addLine = vi.fn()
const quota = vi.fn()
const permissions = { value: ['pos_sale.temporary_item'] }

vi.mock('../composables/useCart', () => ({
  useCart: () => ({ addLine })
}))

vi.mock('../composables/useOperator', () => ({
  useOperator: () => ({
    can: (permission: string) => permissions.value.includes(permission)
  })
}))

vi.mock('../composables/httpClient', () => ({
  apiFetch: (path: string) => quota(path),
  firstApiErrorMessage: () => 'falló'
}))

/** Monta el diálogo abierto y espera el cupo. */
async function open() {
  const wrapper = mount(SpecialLineDialog, { props: { open: false } })

  await wrapper.setProps({ open: true })
  await flushPromises()

  return wrapper
}

/** El campo por su rótulo: `UFormField` se dibuja como una etiqueta. */
function field(wrapper: Awaited<ReturnType<typeof open>>, label: string) {
  const form = wrapper.findAll('label').find((node) => node.text().startsWith(label))

  return form?.find('input')
}

describe('SpecialLineDialog', () => {
  beforeEach(() => {
    addLine.mockReset()
    addLine.mockResolvedValue(undefined)
    quota.mockReset()
    quota.mockResolvedValue({
      used_today: 2,
      limit: 5,
      remaining: 3,
      may_authorize_self: true
    })
    permissions.value = ['pos_sale.temporary_item']
  })

  it('dice cuántas quedan antes de teclear nada', async () => {
    const wrapper = await open()

    // Descubrir el límite al sexto intento es lo que convierte un control en un
    // obstáculo.
    expect(wrapper.text()).toContain('Usaste 2 de 5')
  })

  it('avisa que el supervisor se va a enterar, no después', async () => {
    const wrapper = await open()

    // Un control que el cajero descubre solo cuando ya pasó se siente una
    // trampa; uno que conoce de antemano es un acuerdo (P-11).
    expect(wrapper.text()).toContain('El supervisor recibe el aviso')
  })

  it('el producto sin catálogo manda cantidad y precio unitario', async () => {
    const wrapper = await open()

    await field(wrapper, 'Qué es')?.setValue('Escoba plástica grande')
    await field(wrapper, 'Precio unitario')?.setValue('120')
    await field(wrapper, 'Cantidad')?.setValue('2')

    await wrapper.findAll('button').find((b) => b.text() === 'Agregar a la venta')?.trigger('click')
    await flushPromises()

    expect(addLine).toHaveBeenCalledWith(
      expect.objectContaining({ kind: 'temporary', qty: '2', unit_price: '120' })
    )
  })

  it('cobrar un monto no manda cantidad: no hay unidades que contar', async () => {
    const wrapper = await open()

    await wrapper.findAll('button').find((b) => b.text() === 'Cobrar un monto')?.trigger('click')

    await field(wrapper, 'Qué es')?.setValue('Mano de obra')
    await field(wrapper, 'Importe')?.setValue('500')

    await wrapper.findAll('button').find((b) => b.text() === 'Agregar a la venta')?.trigger('click')
    await flushPromises()

    const payload = addLine.mock.calls[0]?.[0]

    expect(payload).toMatchObject({ kind: 'amount', amount: '500' })
    expect(payload).not.toHaveProperty('qty')
  })

  it('el cupo agotado pide el PIN del supervisor antes de dejar agregar', async () => {
    quota.mockResolvedValue({
      used_today: 5,
      limit: 5,
      remaining: 0,
      may_authorize_self: true
    })

    const wrapper = await open()

    await field(wrapper, 'Qué es')?.setValue('Escoba')
    await field(wrapper, 'Precio unitario')?.setValue('120')

    const add = wrapper.findAll('button').find((b) => b.text() === 'Agregar a la venta')

    // El sexto del día no se manda y se rechaza: se pide la firma antes, en esta
    // misma terminal.
    expect(wrapper.text()).toContain('PIN de autorización del supervisor')
    expect(add?.attributes('disabled')).toBeDefined()
  })

  it('sin permiso propio también pide la firma', async () => {
    permissions.value = []

    const wrapper = await open()

    expect(wrapper.text()).toContain('PIN de autorización del supervisor')
  })

  it('no manda nada sin nombre: es lo único que queda en el ticket', async () => {
    const wrapper = await open()

    await field(wrapper, 'Precio unitario')?.setValue('120')

    const add = wrapper.findAll('button').find((b) => b.text() === 'Agregar a la venta')

    expect(add?.attributes('disabled')).toBeDefined()
  })
})
