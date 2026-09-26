import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, ref } from 'vue'
import { type OnlyRule, vOnly } from './only'

/**
 * `v-only`: lo que cada campo acepta (transversal a la interfaz).
 *
 * Lo que se prueba no es el filtro en abstracto sino los casos que aparecen en
 * una caja de verdad: el importe **pegado desde una planilla** con símbolo de
 * moneda y separadores, la coma del teclado numérico de la tablet, el ajuste de
 * inventario que **resta**, y el PIN donde una letra no tiene nada que hacer.
 */

/** Un campo con la regla puesta, que refleja lo que el modelo guardó. */
function field(rule: OnlyRule, initial = '') {
  const model = ref(initial)

  const wrapper = mount(
    defineComponent({
      directives: { only: vOnly },
      setup: () => ({ model, rule }),
      template: '<input v-only="rule" :value="model" @input="model = $event.target.value">'
    })
  )

  return {
    /** Escribe o pega, según lo haría una persona. */
    async type(value: string) {
      const input = wrapper.get('input')
      ;(input.element as HTMLInputElement).value = value
      await input.trigger('input')

      return model.value
    }
  }
}

describe('v-only', () => {
  describe('digits', () => {
    it('una letra en el PIN no llega al modelo', async () => {
      const pin = field('digits')

      expect(await pin.type('12a34')).toBe('1234')
    })

    it('ni el espacio ni el guion', async () => {
      const pin = field('digits')

      expect(await pin.type('12 34-')).toBe('1234')
    })
  })

  describe('decimal', () => {
    it('un importe pegado de una planilla queda limpio', async () => {
      const price = field('decimal')

      // Es el camino por el que entra la mitad de la basura: el símbolo de
      // moneda y el separador de miles viajan con el número copiado.
      expect(await price.type('C$ 1,234.50')).toBe('1234.50')
    })

    it('la coma con centavos detrás es el separador decimal', async () => {
      const price = field('decimal')

      // La gente teclea "45,50" con toda naturalidad, y el motor de cálculo
      // espera punto: guardarlo tal cual terminaría en un importe de cuarenta y
      // cinco.
      expect(await price.type('45,50')).toBe('45.50')
    })

    it('la coma con tres dígitos detrás es separador de miles', async () => {
      const price = field('decimal')

      // Es la otra mitad de la misma trampa: leerla como decimal convertiría mil
      // doscientos treinta y cuatro en uno con doscientos treinta y cuatro.
      expect(await price.type('1,234')).toBe('1234')
    })

    it('un solo separador, aunque se teclee de más', async () => {
      const price = field('decimal')

      expect(await price.type('45.5.7')).toBe('45.57')
    })

    it('no admite el menos: un precio negativo no existe', async () => {
      const price = field('decimal')

      expect(await price.type('-45.50')).toBe('45.50')
    })
  })

  describe('signed', () => {
    it('el ajuste que resta conserva su signo', async () => {
      const qty = field('signed')

      // Quitarle el menos a un ajuste lo convierte en una entrada de mercadería
      // que nunca llegó, y el stock queda mintiendo hacia arriba.
      expect(await qty.type('-12.5')).toBe('-12.5')
    })

    it('el menos solo vale adelante', async () => {
      const qty = field('signed')

      expect(await qty.type('12-5')).toBe('125')
    })
  })

  describe('code', () => {
    it('el código se escribe en mayúsculas mientras se teclea', async () => {
      const code = field('code')

      // Los códigos se comparan: "caja-01" y "CAJA-01" son el mismo y tienen que
      // verse igual.
      expect(await code.type('caja-01')).toBe('CAJA-01')
    })

    it('sin espacios ni acentos: un código no los lleva', async () => {
      const code = field('code')

      expect(await code.type('QUESO seco ñ')).toBe('QUESOSECO')
    })
  })
})
