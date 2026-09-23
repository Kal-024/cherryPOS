import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ReceiptPreview from './ReceiptPreview.vue'
import type { PreviewLine } from '../composables/useReceiptTemplates'

/**
 * La vista previa del comprobante.
 *
 * Era un `<pre>` que concatenaba el campo `text`, así que **la mitad de la
 * plantilla no se veía**: alinear un bloque al centro no movía nada en pantalla,
 * y el logo, los separadores y los espaciadores desaparecían porque no traen
 * `text`. La plantilla se configuraba a ciegas y el resultado aparecía en el
 * primer cliente.
 */

function render(lines: PreviewLine[], width = 32) {
  return mount(ReceiptPreview, {
    props: { lines, width },
    global: { mocks: { $t: (key: string) => key } }
  })
}

describe('ReceiptPreview', () => {
  it('centrar un bloque se ve centrado', () => {
    const wrapper = render([{ kind: 'text', text: 'Comercial El Ejemplo', align: 'center' }])

    expect(wrapper.find('.text-center').text()).toBe('Comercial El Ejemplo')
  })

  it('alinear a la derecha se ve a la derecha', () => {
    const wrapper = render([{ kind: 'text', text: 'Total', align: 'right' }])

    expect(wrapper.find('.text-right').exists()).toBe(true)
  })

  it('sin alineación queda a la izquierda', () => {
    const wrapper = render([{ kind: 'text', text: 'Producto' }])

    expect(wrapper.find('.text-left').exists()).toBe(true)
  })

  it('el total no se parte en dos líneas', () => {
    // Con `lg` agrandando el cuerpo, la línea no entraba en los 48 caracteres y
    // envolvía: el importe caía debajo del rótulo. En el papel salía bien y en
    // la vista previa no — se configuraba mirando algo que no era lo impreso.
    const wrapper = render([
      { kind: 'pair', text: 'TOTAL                                    1,092.48', size: 'lg', bold: true }
    ], 48)

    const line = wrapper.find('.whitespace-pre')

    expect(line.text()).toContain('1,092.48')
    expect(line.classes()).toContain('font-bold')
    expect(wrapper.html()).not.toContain('1.15em')
  })

  it('la negrita y el tamaño chico se distinguen', () => {
    const wrapper = render([
      { kind: 'text', text: 'RUC J0310000000000', size: 'sm' },
      { kind: 'text', text: 'TOTAL', bold: true }
    ])

    expect(wrapper.find('.font-bold').text()).toBe('TOTAL')
    expect(wrapper.html()).toContain('text-[0.85em]')
  })

  it('el separador ocupa el ancho del papel', () => {
    // Una raya que no llega al borde no separa nada: es un guion suelto.
    const wrapper = render([{ kind: 'separator', char: '-' }], 32)

    expect(wrapper.text()).toContain('-'.repeat(32))
  })

  it('el separador respeta su carácter', () => {
    const wrapper = render([{ kind: 'separator', char: '=' }], 10)

    expect(wrapper.text()).toContain('='.repeat(10))
  })

  it('el logo marca su lugar aunque no haya imagen', () => {
    // Un hueco donde va la identidad del negocio se lee como impresora rota.
    const wrapper = render([{ kind: 'logo' }])

    expect(wrapper.text()).not.toBe('')
  })

  it('el cuerpo se achica para que el ticket entre en la columna', async () => {
    // Con un cuerpo fijo, en una columna angosta el ticket se salía por la
    // derecha: se leían las etiquetas y no los importes, que es lo contrario de
    // para qué sirve una vista previa.
    const wrapper = render([{ kind: 'pair', text: 'TOTAL                    1,245.00' }], 48)

    // Sin medida todavía, arranca con el cuerpo cómodo y nunca lo supera.
    const size = Number(/font-size:\s*([\d.]+)px/.exec(wrapper.html())?.[1] ?? '0')

    expect(size).toBeGreaterThan(0)
    expect(size).toBeLessThanOrEqual(13)
  })

  it('el ancho se fija en caracteres, no en píxeles', () => {
    // Lo que decide si un nombre se corta son los 32 caracteres del rollo, no
    // el tamaño de la ventana donde se está configurando.
    const wrapper = render([{ kind: 'text', text: 'x' }], 48)

    expect(wrapper.html()).toContain('48ch')
  })

  it('las líneas de dos columnas conservan sus espacios', () => {
    // Llegan rellenadas por el servidor: colapsarlas descuadra los importes.
    const wrapper = render([{ kind: 'pair', text: 'Gaseosa            45.00' }])

    expect(wrapper.find('.whitespace-pre').exists()).toBe(true)
  })
})
