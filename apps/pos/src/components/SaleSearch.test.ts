import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SaleSearch from './SaleSearch.vue'
import type { CatalogProduct } from '../composables/useCatalog'

/**
 * El campo de búsqueda del perfil `scan_first`.
 *
 * *"El cajero no toca el ratón."* Lo que se prueba acá es esa premisa: que una
 * lectura del lector produzca **una** línea sin confirmación, que las flechas y
 * Enter basten para elegir, y que el campo quede limpio y listo para la lectura
 * siguiente.
 */

const cheese: CatalogProduct = {
  id: 'p1',
  sku: 'QUESO',
  name: 'Queso seco',
  price: '180.00',
  tracks_stock: true,
  is_exempt: false,
  barcodes: [{ code: '7501234567890', embedded: 'none' }]
}

const cola: CatalogProduct = {
  id: 'p2',
  sku: 'GAS-15',
  name: 'Gaseosa cola 1.5 L',
  price: '45.00',
  tracks_stock: true,
  is_exempt: false,
  barcodes: [{ code: '7509999999999', embedded: 'none' }]
}

const results = vi.fn<(term: string, limit?: number) => CatalogProduct[]>()
const byBarcode = vi.fn<(code: string) => CatalogProduct | null>()

vi.mock('../composables/useCatalog', () => ({
  useCatalog: () => ({
    search: (term: string, limit?: number) => results(term, limit),
    byBarcode: (code: string) => byBarcode(code),
    favourites: { value: [] }
  })
}))

describe('SaleSearch', () => {
  beforeEach(() => {
    results.mockReset()
    byBarcode.mockReset()
    byBarcode.mockReturnValue(null)
    results.mockReturnValue([])
  })

  it('una lectura del lector agrega sin pedir confirmación', async () => {
    byBarcode.mockReturnValue(cheese)

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('7501234567890')
    await input.trigger('keydown.enter')

    // Mostrar resultados y esperar confirmación convertiría una lectura en dos
    // pulsaciones. En hora pico eso es la diferencia del día.
    expect(wrapper.emitted('scan')?.[0]).toEqual(['7501234567890', '1'])
    expect(wrapper.emitted('pick')).toBeUndefined()
  })

  it('el campo queda limpio para la lectura siguiente', async () => {
    byBarcode.mockReturnValue(cheese)

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('7501234567890')
    await input.trigger('keydown.enter')

    expect((input.element as HTMLInputElement).value).toBe('')
  })

  it('tras agregar, la lista deja de tapar el carrito', async () => {
    byBarcode.mockReturnValue(cheese)
    // Con el campo vacío la búsqueda devuelve los más usados por este cajero
    // (D-04). Como el foco vuelve solo al campo tras cada acción, dejar la lista
    // abierta con esos favoritos tapaba las líneas de forma permanente.
    results.mockReturnValue([cheese, cola])

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('7501234567890')
    await input.trigger('keydown.enter')

    expect(wrapper.findAll('button')).toHaveLength(0)
  })

  it('Enter elige el resultado resaltado', async () => {
    results.mockReturnValue([cheese, cola])

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('q')
    await input.trigger('keydown.enter')

    expect(wrapper.emitted('pick')?.[0]).toEqual([cheese, '1'])
  })

  it('las flechas mueven la selección sin tocar el ratón', async () => {
    results.mockReturnValue([cheese, cola])

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('a')
    await input.trigger('keydown.down')
    await input.trigger('keydown.enter')

    expect(wrapper.emitted('pick')?.[0]).toEqual([cola, '1'])
  })

  it('la selección da la vuelta al llegar al final', async () => {
    results.mockReturnValue([cheese, cola])

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('a')
    await input.trigger('keydown.up')
    await input.trigger('keydown.enter')

    // Subir desde el primero lleva al último: el cajero no tiene que mirar
    // dónde está parado.
    expect(wrapper.emitted('pick')?.[0]).toEqual([cola, '1'])
  })

  it('avisa cuando no hay nada que coincida, sin dejar la lista vacía', async () => {
    results.mockReturnValue([])

    const wrapper = mount(SaleSearch)
    await wrapper.get('input').setValue('martillo')

    // Error con acción: decir qué se buscó es lo que permite corregir el
    // término sin volver a escribirlo entero.
    expect(wrapper.text()).toContain('martillo')
  })

  it('la cantidad se teclea antes del producto', async () => {
    results.mockReturnValue([cheese])

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('3*queso')
    await input.trigger('keydown.enter')

    // El término que se busca es "queso": el multiplicador no es parte del
    // nombre de nada.
    expect(wrapper.emitted('pick')?.[0]).toEqual([cheese, '3'])
  })

  it('la cantidad armada espera al lector', async () => {
    byBarcode.mockReturnValue(cheese)

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    // "3*" a secas no agrega nada: arma la cantidad y espera. Es como se teclea
    // de verdad cuando después viene el lector.
    await input.setValue('3*')
    await input.trigger('keydown.enter')

    expect(wrapper.emitted('scan')).toBeUndefined()
    expect(wrapper.text()).toContain('×3')

    await input.setValue('7501234567890')
    await input.trigger('keydown.enter')

    expect(wrapper.emitted('scan')?.[0]).toEqual(['7501234567890', '3'])
  })

  it('la cantidad no se queda pegada al siguiente producto', async () => {
    byBarcode.mockReturnValue(cheese)

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('3*')
    await input.trigger('keydown.enter')
    await input.setValue('7501234567890')
    await input.trigger('keydown.enter')

    await input.setValue('7501234567890')
    await input.trigger('keydown.enter')

    // Si se quedara pegada, el segundo producto entraría con la cantidad del
    // primero y eso se descubre al cobrar.
    expect(wrapper.emitted('scan')?.[1]).toEqual(['7501234567890', '1'])
  })

  it('un código de barras nunca se lee como cantidad', async () => {
    byBarcode.mockReturnValue(cheese)

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    // El lector es un teclado: trece dígitos y Enter. Si los dígitos sueltos
    // fueran cantidad, cada lectura pediría siete mil quinientas unidades.
    await input.setValue('7501234567890')
    await input.trigger('keydown.enter')

    expect(wrapper.emitted('scan')?.[0]).toEqual(['7501234567890', '1'])
  })

  it('Escape limpia sin emitir nada', async () => {
    results.mockReturnValue([cheese])

    const wrapper = mount(SaleSearch)
    const input = wrapper.get('input')

    await input.setValue('queso')
    await input.trigger('keydown.esc')

    expect((input.element as HTMLInputElement).value).toBe('')
    expect(wrapper.emitted('pick')).toBeUndefined()
  })

  it('Enter con el campo vacío no hace nada', async () => {
    const wrapper = mount(SaleSearch)

    await wrapper.get('input').trigger('keydown.enter')

    expect(wrapper.emitted('pick')).toBeUndefined()
    expect(wrapper.emitted('scan')).toBeUndefined()
  })
})
