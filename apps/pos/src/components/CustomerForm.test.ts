import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CustomerForm from './CustomerForm.vue'
import { emptyCustomerDraft, type CustomerDraft } from '../composables/useCustomers'

/**
 * La ficha de cliente y sus dos clases (P-03, G-10).
 *
 * La clase decide qué se pide. Pedirle cédula al que compra una gaseosa lo
 * ahuyenta; no pedírsela al que abre crédito deja la cuenta sin dueño. Eso es lo
 * que se prueba, junto con la consecuencia menos intuitiva del IVA incluido:
 * exonerar **no abarata** la compra.
 */

function mountForm(draft: CustomerDraft = emptyCustomerDraft()) {
  return mount(CustomerForm, {
    props: {
      modelValue: draft,
      'onUpdate:modelValue': (value: CustomerDraft) => Object.assign(draft, value)
    }
  })
}

describe('CustomerForm', () => {
  it('el cliente de efectivo no necesita cédula', () => {
    const wrapper = mountForm()

    expect(emptyCustomerDraft().kind).toBe('cash')
    expect(wrapper.text()).toContain('Paga y se va. El nombre alcanza.')
    expect(wrapper.text()).not.toContain('La cuenta es personal')
  })

  it('el cliente con cuenta exige cédula', () => {
    const wrapper = mountForm({ ...emptyCustomerDraft(), kind: 'account' })

    expect(wrapper.text()).toContain('Abre crédito: la cédula pasa a ser obligatoria.')
    expect(wrapper.text()).toContain('La cuenta es personal: sin cédula no se sabe de quién es.')
  })

  it('la referencia de la exoneración aparece solo al exonerar', async () => {
    const draft = emptyCustomerDraft()
    const wrapper = mountForm(draft)

    expect(wrapper.text()).not.toContain('Referencia de la exoneración')

    // Y la advertencia que evita la discusión en caja: con el IVA incluido en
    // el precio, el cliente exonerado paga lo mismo.
    expect(wrapper.text()).toContain('Cambia cómo se declara la venta, no cuánto paga el cliente.')

    await wrapper.setProps({ modelValue: { ...draft, is_tax_exempt: true } })

    expect(wrapper.text()).toContain('Referencia de la exoneración')
  })

  it('el teléfono y el porcentaje no aceptan letras; el nombre sí', async () => {
    // Lo que se prueba no es el filtro —eso vive en `directives/only`— sino que
    // esta ficha lo tenga puesto donde corresponde: un teléfono con letras
    // llega al servidor y de ahí al WhatsApp del cliente, y un descuento con
    // letras se guarda como cero sin que nadie lo note en caja.
    const draft = emptyCustomerDraft()
    const wrapper = mountForm(draft)

    const fields = wrapper.findAll('input').filter((input) => input.attributes('type') !== 'checkbox')
    const type = async (index: number, value: string) => {
      const input = fields[index]!

      ;(input.element as HTMLInputElement).value = value
      await input.trigger('input')
    }

    // Nombre, cédula, RUC, teléfono, WhatsApp, correo, código, descuento.
    await type(3, '8888-8888 casa')
    await type(7, '12,5%')

    // Y el nombre queda intacto: ahí un filtro estorbaría más de lo que protege.
    await type(0, 'María Ñurinda 2do')

    expect(draft.phone).toBe('8888-8888 ')
    expect(draft.discount_percent).toBe('12.5')
    expect(draft.name).toBe('María Ñurinda 2do')
  })

  it('emite submit al enviar el formulario', async () => {
    const wrapper = mountForm()

    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('submit')).toHaveLength(1)
  })
})
