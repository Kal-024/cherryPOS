import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ModifierPicker from './ModifierPicker.vue'
import type { CatalogProduct } from '../composables/useCatalog'

/**
 * Preguntar antes de mandar el plato (B-06).
 *
 * Las tres reglas que la pantalla tiene que sostener: el obligatorio **no deja
 * confirmar** —descubrirlo después manda al mesero de vuelta a la mesa—, un
 * grupo de una sola respuesta **reemplaza** en vez de acumular, y el precio del
 * agregado se ve mientras se elige, porque el cliente pregunta cuánto sale
 * mientras el mesero teclea.
 */

const steak: CatalogProduct = {
  id: 'p1',
  sku: 'PLATO-1',
  name: 'Lomo',
  price: '350.00',
  tracks_stock: true,
  is_exempt: false,
  modifier_groups: [
    {
      id: 'g1',
      name: 'Término',
      min_select: 1,
      max_select: 1,
      modifiers: [
        { id: 'm1', name: 'Término medio', price_delta: '0.00', is_default: false },
        { id: 'm2', name: 'Tres cuartos', price_delta: '0.00', is_default: false }
      ]
    },
    {
      id: 'g2',
      name: 'Extras',
      min_select: 0,
      max_select: null,
      modifiers: [
        { id: 'm3', name: 'Doble queso', price_delta: '45.00', is_default: false }
      ]
    }
  ]
}

function mountPicker() {
  return mount(ModifierPicker, { props: { product: steak } })
}

function option(wrapper: ReturnType<typeof mountPicker>, label: string) {
  return wrapper.findAll('button').find((button) => button.text().includes(label))!
}

describe('ModifierPicker', () => {
  it('no deja confirmar mientras falte un obligatorio', async () => {
    const wrapper = mountPicker()

    expect(wrapper.text()).toContain('Falta responder: Término')

    const confirm = wrapper.findAll('button').at(-1)!
    expect(confirm.attributes('disabled')).toBeDefined()

    await option(wrapper, 'Término medio').trigger('click')

    expect(wrapper.text()).not.toContain('Falta responder')
    expect(wrapper.findAll('button').at(-1)!.attributes('disabled')).toBeUndefined()
  })

  it('un grupo de una sola respuesta reemplaza en vez de acumular', async () => {
    const wrapper = mountPicker()

    await option(wrapper, 'Término medio').trigger('click')
    await option(wrapper, 'Tres cuartos').trigger('click')
    await wrapper.findAll('button').at(-1)!.trigger('click')

    const emitted = wrapper.emitted('confirm')![0]![0] as { modifiers: string[] }

    // Obligar a destildar antes sería un toque de más por plato.
    expect(emitted.modifiers).toEqual(['m2'])
  })

  it('muestra cuánto suman los agregados', async () => {
    const wrapper = mountPicker()

    await option(wrapper, 'Término medio').trigger('click')
    await option(wrapper, 'Doble queso').trigger('click')

    expect(wrapper.text()).toContain('45.00')
  })

  it('la nota para cocina viaja con la elección', async () => {
    const wrapper = mountPicker()

    await option(wrapper, 'Término medio').trigger('click')
    await wrapper.find('input').setValue('Sin sal')
    await wrapper.findAll('button').at(-1)!.trigger('click')

    const emitted = wrapper.emitted('confirm')![0]![0] as { notes: string }

    expect(emitted.notes).toBe('Sin sal')
  })
})
