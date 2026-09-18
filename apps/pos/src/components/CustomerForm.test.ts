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

  it('emite submit al enviar el formulario', async () => {
    const wrapper = mountForm()

    await wrapper.find('form').trigger('submit')

    expect(wrapper.emitted('submit')).toHaveLength(1)
  })
})
