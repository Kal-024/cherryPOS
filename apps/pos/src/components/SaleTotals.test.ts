import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SaleTotals from './SaleTotals.vue'
import type { Sale } from '../composables/useCart'

/**
 * El total y su desglose.
 *
 * *"Densidad alta: una pantalla de caja muestra información, no aire."* Lo que
 * se prueba es que solo aparezca lo que tiene valor: un descuento en cero o un
 * exento que no existe gastan la línea que necesita otra cosa.
 */

function sale(overrides: Partial<Sale> = {}): Sale {
  return {
    id: 's1',
    number: null,
    status: 'draft',
    sale_type: 'counter',
    label: null,
    currency_code: 'NIO',
    gross: '115.00',
    line_discount_total: '0.00',
    sale_discount: '0.00',
    discount_total: '0.00',
    subtotal: '100.00',
    taxable_base: '100.00',
    exempt_total: '0.00',
    tax_total: '15.00',
    total: '115.00',
    cash_rounding: '0.00',
    tip_amount: '0.00',
    paid: '0.00',
    balance: '115.00',
    customer_id: null,
    ...overrides
  }
}

describe('SaleTotals', () => {
  it('sin propina no gasta una línea en ella', () => {
    const wrapper = mount(SaleTotals, { props: { sale: sale() } })

    expect(wrapper.text()).not.toContain('Propina')
    expect(wrapper.text()).not.toContain('A cobrar')
  })

  it('con propina, el número grande es lo que hay que cobrar y el total sigue siendo el fiscal', () => {
    const wrapper = mount(SaleTotals, {
      props: { sale: sale({ tip_amount: '11.50' }) }
    })

    const text = wrapper.text()

    // El documento dice 115,00 y el cliente entrega 126,50. Si alguna vez el
    // total pasara a 126,50, lo que sonaría no sería este renglón sino
    // `tax_difference` distinto de cero en cada cuenta.
    expect(text).toContain('115.00')
    expect(text).toContain('11.50')
    expect(text).toContain('126.50')
    expect(wrapper.find('.pos-total').text()).toContain('126.50')
  })

  it('con vuelto, el número grande es el vuelto', () => {
    // Un billete de 500 sobre 115. Lo que el cajero necesita leer de lejos ya no
    // es lo que falta cobrar sino lo que tiene que devolver: dejarlo en letra
    // chica obligaba a buscarlo con el cliente esperando la mano.
    const wrapper = mount(SaleTotals, {
      props: { sale: sale({ paid: '500.00', balance: '0.00', change: '385.00' }) }
    })

    expect(wrapper.text()).toContain('Vuelto')
    expect(wrapper.find('.pos-total').text()).toContain('385.00')
  })

  it('sin vuelto el número grande sigue siendo el total', () => {
    const wrapper = mount(SaleTotals, {
      props: { sale: sale({ paid: '100.00', balance: '15.00', change: '0.00' }) }
    })

    expect(wrapper.text()).not.toContain('Vuelto')
    expect(wrapper.find('.pos-total').text()).toContain('115.00')
  })

  it('con vuelto y propina el vuelto manda, y hay un solo número grande', () => {
    const wrapper = mount(SaleTotals, {
      props: { sale: sale({ tip_amount: '11.50', paid: '200.00', balance: '0.00', change: '73.50' }) }
    })

    // Dos números grandes compitiendo serían peores que ninguno.
    expect(wrapper.findAll('.pos-total')).toHaveLength(1)
    expect(wrapper.find('.pos-total').text()).toContain('73.50')
  })

  it('sin descuento no muestra la línea de descuento', () => {
    const wrapper = mount(SaleTotals, { props: { sale: sale() } })

    expect(wrapper.text()).not.toContain('Descuento')
  })

  it('con descuento lo muestra en negativo', () => {
    const wrapper = mount(SaleTotals, {
      props: { sale: sale({ discount_total: '12.50' }) }
    })

    expect(wrapper.text()).toContain('Descuento')
    expect(wrapper.text()).toContain('−')
  })

  it('separa el exento de la base gravada', () => {
    const wrapper = mount(SaleTotals, {
      props: { sale: sale({ exempt_total: '250.00', taxable_base: '100.00' }) }
    })

    // El libro de ventas los declara distinto, y la pantalla también: el cajero
    // que ve "exento" sabe por qué el IVA no cuadra con el total.
    expect(wrapper.text()).toContain('Exento')
  })

  it('el redondeo solo aparece cuando hubo redondeo', () => {
    expect(mount(SaleTotals, { props: { sale: sale() } }).text()).not.toContain('Redondeo')

    const rounded = mount(SaleTotals, { props: { sale: sale({ cash_rounding: '-0.05' }) } })

    expect(rounded.text()).toContain('Redondeo')
  })

  it('sin venta el total es cero y la pantalla no se rompe', () => {
    const wrapper = mount(SaleTotals, { props: { sale: null } })

    // Es el estado de arranque de la caja: antes de la primera lectura no hay
    // venta, y el total tiene que decir algo igual.
    expect(wrapper.find('.pos-total').exists()).toBe(true)
  })
})
