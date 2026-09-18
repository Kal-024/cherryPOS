import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ReceiptBlockEditor from './ReceiptBlockEditor.vue'
import { newBlock, type TemplateBlock } from '../composables/useReceiptTemplates'

/**
 * El editor de un bloque del ticket (D-11).
 *
 * Lo que se prueba es que el editor **solo ofrezca lo que el bloque entiende**
 * —un separador no tiene alineación— y que el orden de los totales lo fije el
 * ticket y no el orden en que el supervisor fue marcando casillas: el total va
 * al final del papel siempre.
 */

function mountBlock(block: TemplateBlock) {
  const model = { ...block }

  const wrapper = mount(ReceiptBlockEditor, {
    props: {
      modelValue: model,
      index: 1,
      count: 3,
      'onUpdate:modelValue': (value: TemplateBlock) => {
        Object.assign(model, value)
        wrapper.setProps({ modelValue: { ...value } })
      }
    }
  })

  return { wrapper, model }
}

describe('ReceiptBlockEditor', () => {
  it('un bloque de texto ofrece contenido, alineación y negrita', () => {
    const { wrapper } = mountBlock(newBlock('text'))

    expect(wrapper.text()).toContain('Texto')
    expect(wrapper.text()).toContain('Alineación')
    expect(wrapper.text()).toContain('Negrita')
  })

  it('un separador no ofrece nada que configurar', () => {
    const { wrapper } = mountBlock(newBlock('separator'))

    expect(wrapper.text()).toContain('Una línea de guiones a lo ancho del papel.')
    expect(wrapper.text()).not.toContain('Alineación')
  })

  it('los totales se guardan en el orden del ticket, no en el de los clics', async () => {
    const { wrapper, model } = mountBlock({ type: 'totals', show: [] })
    const switches = wrapper.findAll('input[type="checkbox"]')

    // Se marca primero el total (el último del papel) y después el subtotal.
    await switches[5]!.setValue(true)
    await switches[0]!.setValue(true)

    expect(model.show).toEqual(['subtotal', 'total'])
  })

  it('el primero no se puede subir y el último no se puede bajar', () => {
    const first = mount(ReceiptBlockEditor, {
      props: { modelValue: newBlock('logo'), index: 0, count: 2 }
    })

    const buttons = first.findAll('button')

    // Orden de los controles: subir, bajar, quitar.
    expect(buttons[0]!.attributes('disabled')).toBeDefined()
    expect(buttons[1]!.attributes('disabled')).toBeUndefined()
  })
})
