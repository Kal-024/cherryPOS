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

    it('el porcentaje pegado con su signo detrás sigue siendo decimal', async () => {
      const discount = field('decimal')

      // El `%` hacía que el valor no terminara en dígito y la coma pasaba por
      // separador de miles: un descuento del doce y medio por ciento entraba
      // como ciento veinticinco.
      expect(await discount.type('12,5 %')).toBe('12.5')
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

  describe('phone', () => {
    it('la letra no entra, pero el guion del número sí', async () => {
      const phone = field('phone')

      // En Nicaragua el teléfono se escribe 8888-8888: filtrarlo a dígitos
      // pelados sería pelear con quien copia una agenda entera.
      expect(await phone.type('8888-8888 ext')).toBe('8888-8888 ')
    })

    it('el prefijo de país sobrevive adelante y no en el medio', async () => {
      expect(await field('phone').type('+505 8888 8888')).toBe('+505 8888 8888')
      expect(await field('phone').type('505+8888')).toBe('5058888')
    })
  })

  describe('slug', () => {
    it('el código de un rol va en minúsculas', async () => {
      const role = field('slug')

      // Al revés que `code`: los roles del instalador están sembrados en
      // minúscula y un "CASHIER" tecleado a mano sería otro rol, sin que nada
      // lo denuncie hasta que un permiso no aplica.
      expect(await role.type('Cashier')).toBe('cashier')
    })

    it('sin espacios ni acentos', async () => {
      const role = field('slug')

      expect(await role.type('jefe de área')).toBe('jefederea')
    })
  })

  describe('code', () => {
    it('el código se escribe en mayúsculas mientras se teclea', async () => {
      const code = field('code')

      // Los códigos se comparan: "caja-01" y "CAJA-01" son el mismo y tienen que
      // verse igual.
      expect(await code.type('caja-01')).toBe('CAJA-01')
    })

    it('la cédula entra tal cual, con su letra en mayúscula', async () => {
      const nationalId = field('code')

      // La cédula nicaragüense es `001-010180-0001A`: alfanumérica con guiones,
      // exactamente la familia de `code`. Es además la clave natural con la que
      // se fusionan persona, cliente y empleado (B-11), así que su forma tiene
      // que ser una sola.
      expect(await nationalId.type('001-010180-0001a')).toBe('001-010180-0001A')
    })

    it('sin espacios ni acentos: un código no los lleva', async () => {
      const code = field('code')

      expect(await code.type('QUESO seco ñ')).toBe('QUESOSECO')
    })
  })
})
