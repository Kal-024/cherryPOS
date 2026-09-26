import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SaleSplitDialog from './SaleSplitDialog.vue'
import type { SaleLine } from '../composables/useCart'
import type { DiningTable } from '../composables/useDining'

/**
 * Traspasar o dividir (F1-B).
 *
 * Dos reglas de pantalla que evitan cobrar mal: **sin líneas elegidas se mueve
 * todo** —el caso común es el grupo que se cambia de mesa, y marcarlas todas
 * sería un toque por plato— y **mover todo no divide nada**, así que dividir
 * queda deshabilitado hasta que algo se quede en la cuenta original.
 */

const line = (id: string, description: string, total: string): SaleLine => ({
  id,
  sequence: 1,
  description,
  kind: 'product',
  qty: '1.0000',
  unit_price: total,
  line_discount: '0.00',
  sale_discount_share: '0.00',
  gross: total,
  taxable_base: total,
  tax_total: '0.00',
  total,
  group_name: null,
  product_id: 'p1'
})

const table = (id: string, code: string, state: DiningTable['state']): DiningTable => ({
  id,
  area_id: null,
  code,
  name: code,
  seats: 4,
  width: 140,
  height: 120,
  pos_x: 0,
  pos_y: 0,
  shape: 'square',
  merged_into_id: null,
  state,
  sale: null
})

function mountDialog() {
  return mount(SaleSplitDialog, {
    props: {
      lines: [line('l1', 'Lomo', '350.00'), line('l2', 'Cerveza', '60.00')],
      tables: [table('t1', 'M2', 'free'), table('t2', 'M3', 'occupied'), table('t3', 'M4', 'merged')]
    }
  })
}

function lineButton(wrapper: ReturnType<typeof mountDialog>, label: string) {
  return wrapper.findAll('button').find((button) => button.text().includes(label))!
}

describe('SaleSplitDialog', () => {
  it('sin elegir nada se mueve la cuenta entera', () => {
    const wrapper = mountDialog()

    expect(wrapper.text()).toContain('Se mueve la cuenta entera.')
  })

  it('al elegir líneas dice cuánto se mueve', async () => {
    const wrapper = mountDialog()

    await lineButton(wrapper, 'Lomo').trigger('click')

    expect(wrapper.text()).toContain('Se mueven 350.00')
  })

  it('dividir exige que algo se quede en la cuenta original', async () => {
    const wrapper = mountDialog()
    const split = wrapper.findAll('button').find((button) => button.text().includes('Dividir'))!

    expect(split.attributes('disabled')).toBeDefined()

    await lineButton(wrapper, 'Lomo').trigger('click')
    expect(split.attributes('disabled')).toBeUndefined()

    // Con todas elegidas no hay división: quedaría una cuenta vacía.
    await lineButton(wrapper, 'Cerveza').trigger('click')
    expect(split.attributes('disabled')).toBeDefined()
  })

  it('las mesas unidas no son destino y las ocupadas se avisan', () => {
    const wrapper = mountDialog()
    const options = wrapper.findAll('option').map((option) => option.text())

    // Una mesa unida no acepta cuenta propia: ofrecerla llevaría a un 422.
    expect(options).not.toContain('M4')
    expect(options).toContain('M3 · con cuenta')
  })
})
