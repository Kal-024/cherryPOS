import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SaleLines from './SaleLines.vue'
import type { SaleLine } from '../composables/useCart'

/**
 * Las líneas del carrito.
 *
 * Dos cosas importan más que el resto: que la cantidad se lea como la escribiría
 * una persona —`2`, no `2.0000`— y que el combo explique por qué esa línea trae
 * descuento.
 */

function line(overrides: Partial<SaleLine> = {}): SaleLine {
  return {
    id: 'l1',
    sequence: 1,
    description: 'Gaseosa cola 1.5 L',
    kind: 'product',
    qty: '2.0000',
    unit_price: '45.0000',
    line_discount: '0.00',
    sale_discount_share: '0.00',
    gross: '90.00',
    taxable_base: '78.26',
    tax_total: '11.74',
    total: '90.00',
    group_name: null,
    product_id: 'p1',
    ...overrides
  }
}

describe('SaleLines', () => {
  it('en un mostrador la columna de curso no existe', () => {
    const wrapper = mount(SaleLines, { props: { lines: [line()] } })

    // Una pulpería no sirve por tiempos: la columna solo gastaría ancho de
    // pantalla en una caja que muestra información, no aire.
    expect(wrapper.text()).not.toContain('Curso')
  })

  it('en el salón cada línea lleva su curso', () => {
    const wrapper = mount(SaleLines, {
      props: { lines: [line({ course: 2 })], courses: true }
    })

    // El nombre del curso sale del catálogo i18n, no de un número suelto: el
    // mesero canta "el fuerte", no "el curso dos".
    expect(wrapper.text()).toContain('Curso')
    expect(wrapper.find('select').element.value).toBe('2')
  })

  it('sin líneas invita a empezar en vez de mostrar una tabla vacía', () => {
    const wrapper = mount(SaleLines, { props: { lines: [] } })

    // Errores y estados vacíos con acción, nunca callejones sin salida.
    expect(wrapper.text()).toContain('Pasá el lector')
  })

  it('la cantidad se lee como la escribiría una persona', () => {
    const wrapper = mount(SaleLines, { props: { lines: [line()] } })

    expect(wrapper.text()).toContain('2')
    expect(wrapper.text()).not.toContain('2.0000')
  })

  it('un peso fraccionado conserva sus decimales', () => {
    const wrapper = mount(SaleLines, { props: { lines: [line({ qty: '0.8470' })] } })

    // Viene de una etiqueta de balanza: 0,847 kg de queso.
    expect(wrapper.text()).toContain('0.847')
  })

  it('el combo explica por qué la línea trae descuento', () => {
    const wrapper = mount(SaleLines, {
      props: {
        lines: [line({ group_name: 'Combo Familiar', line_discount: '21.82' })]
      }
    })

    expect(wrapper.text()).toContain('Combo Familiar')
    expect(wrapper.text()).toContain('−')
  })

  it('quitar una línea avisa a la pantalla', async () => {
    const wrapper = mount(SaleLines, { props: { lines: [line()] } })

    await wrapper.get('[aria-label="Quitar línea"]').trigger('click')

    expect(wrapper.emitted('remove')?.[0]).toEqual(['l1'])
  })

  it('con la caja ocupada no se puede quitar dos veces', () => {
    const wrapper = mount(SaleLines, { props: { lines: [line()], busy: true } })

    expect(wrapper.get('[aria-label="Quitar línea"]').attributes('disabled')).toBeDefined()
  })

  it('la cantidad se corrige sobre la línea, sin quitarla y volver a pasarla', async () => {
    const wrapper = mount(SaleLines, { props: { lines: [line()] } })

    // Tocar la cantidad abre el campo en blanco: quien la toca viene a teclear
    // otra, no a editar la que hay.
    await wrapper.get('button.pos-amount').trigger('click')

    const field = wrapper.get('input')
    expect((field.element as HTMLInputElement).value).toBe('')

    await field.setValue('3')
    await field.trigger('keydown.enter')

    expect(wrapper.emitted('qty')?.[0]).toEqual(['l1', '3'])
  })

  it('una cantidad vacía o en cero no toca nada', async () => {
    const wrapper = mount(SaleLines, { props: { lines: [line()] } })

    await wrapper.get('button.pos-amount').trigger('click')

    const field = wrapper.get('input')
    await field.setValue('0')
    await field.trigger('keydown.enter')

    // Cero no borra la línea: quitar tiene su propio gesto, y un cero de más no
    // puede hacer desaparecer lo que el cliente ya puso en el mostrador.
    expect(wrapper.emitted('qty')).toBeUndefined()
    expect(wrapper.emitted('remove')).toBeUndefined()
  })

  it('la línea activa se ve', () => {
    const wrapper = mount(SaleLines, {
      props: { lines: [line(), line({ id: 'l2', sequence: 2 })], activeId: 'l2' }
    })

    const rows = wrapper.findAll('tbody tr')
    expect(rows[0]!.classes()).not.toContain('bg-primary/10')
    expect(rows[1]!.classes()).toContain('bg-primary/10')
  })
})
