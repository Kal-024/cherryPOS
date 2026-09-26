import type { Directive } from 'vue'

/**
 * `v-only`: filtra lo que un campo acepta (H7, transversal a toda la interfaz).
 *
 * **Una regla en un solo lugar, no cuarenta parches.** La aplicación tiene un
 * centenar de campos de texto y hasta ahora cada pantalla decidía por su cuenta:
 * unos declaraban `type="number"`, otros `inputmode`, y el PIN del login aceptaba
 * letras. Cuarenta validaciones sueltas se desincronizan; esta es la misma en
 * todas.
 *
 * Cuatro reglas, elegidas por lo que el dato **es**:
 *
 *  - `digits` — PIN, cantidades enteras, sillas, conteo de billetes.
 *  - `decimal` — precios, importes, tasas, límites, topes de descuento. Entiende
 *    la coma según el contexto: ver `separators()`, que es donde vive la
 *    diferencia entre "mil doscientos treinta y cuatro con cincuenta" y
 *    "cuarenta y cinco con cincuenta".
 *  - `code` — SKU y códigos de sucursal, terminal, empleado, mesa o zona:
 *    alfanumérico sin espacios, y se escribe en mayúsculas mientras se teclea
 *    porque los códigos se comparan y "caja-01" no es "CAJA-01" a la vista.
 *  - `integer` — como `digits` pero admite el signo.
 *  - `signed` — decimal con signo: el ajuste de inventario que **resta** y la
 *    línea de una devolución, que viaja en negativo. Quitarle el menos a un
 *    ajuste lo convertiría en una entrada de mercadería que nunca llegó.
 *
 * Los campos libres —descripciones, notas, referencias de pago, nombres,
 * direcciones— **no se tocan**: ahí la validación estorbaría más de lo que
 * protege. Un nombre lleva tildes, una referencia lleva guiones y un apellido
 * puede llevar un número.
 *
 * **Filtra también lo que se pega.** Es el camino por el que entra la mitad de la
 * basura: un importe copiado de una planilla trae el símbolo de moneda y los
 * separadores de miles.
 *
 * Y **no reemplaza al servidor**: las reglas de Laravel son las que mandan. Esto
 * evita el viaje de ida y vuelta, no la validación.
 */

export type OnlyRule = 'digits' | 'integer' | 'decimal' | 'signed' | 'code'

/** Deja lo que la regla admite y descarta el resto, en el orden teclado. */
function clean(value: string, rule: OnlyRule): string {
  if (rule === 'code') {
    return value.replace(/[^A-Za-z0-9._-]/g, '').toUpperCase()
  }

  if (rule === 'digits') {
    return value.replace(/\D/g, '')
  }

  if (rule === 'integer') {
    const sign = value.startsWith('-') ? '-' : ''

    return sign + value.replace(/\D/g, '')
  }

  const sign = rule === 'signed' && value.startsWith('-') ? '-' : ''
  const [whole, ...rest] = separators(value).replace(/[^\d.]/g, '').split('.')
  const digits = rest.length > 0 ? `${whole}.${rest.join('')}` : (whole ?? '')

  return sign + digits
}

/**
 * Qué significa la coma en un importe.
 *
 * Nicaragua escribe `1,234.50`: la coma separa miles y el punto, centavos. Pero
 * el teclado de una tablet a veces entrega coma decimal, y la gente teclea
 * `45,50` con toda naturalidad. Tomar siempre la coma como decimal convertía
 * **`C$ 1,234.50` pegado de una planilla en 1.2345** — un importe mil veces más
 * chico, sin que nada lo denunciara. Tomarla siempre como miles convertía `45,50`
 * en cuatro mil quinientos cincuenta.
 *
 * Así que se lee el contexto, que es lo único que distingue los dos casos:
 *
 *  - Si ya hay un punto, el punto es el decimal y **las comas son de miles**.
 *  - Si no hay punto y la coma trae uno o dos dígitos detrás, son **centavos**.
 *  - Si no hay punto y trae tres, es un **separador de miles**.
 */
function separators(value: string): string {
  if (value.includes('.')) {
    return value.replace(/,/g, '')
  }

  return /,\d{1,2}$/.test(value) ? value.replace(/,/g, '.') : value.replace(/,/g, '')
}

/** El `input` real: `UInput` envuelve el suyo en un contenedor. */
function inputOf(element: HTMLElement): HTMLInputElement | null {
  if (element instanceof HTMLInputElement) return element

  return element.querySelector('input')
}

export const vOnly: Directive<HTMLElement, OnlyRule | undefined> = {
  mounted(element, binding) {
    const rule = binding.value ?? 'digits'
    const input = inputOf(element)

    if (!input) return

    // El teclado correcto en la tablet: pedir un PIN y ofrecer el teclado
    // alfabético es hacer trabajar al cajero por gusto.
    if (rule === 'digits' || rule === 'integer') input.inputMode = 'numeric'
    if (rule === 'decimal' || rule === 'signed') input.inputMode = 'decimal'
    if (rule === 'code') input.autocapitalize = 'characters'

    /**
     * Se corrige en `input` y no en `keydown`.
     *
     * Bloquear teclas deja fuera el pegado, el dictado y el teclado en pantalla
     * de Android, que no emiten `keydown` por carácter. Limpiar el valor cubre
     * todos los caminos con una sola regla.
     */
    const handler = () => {
      const kept = clean(input.value, rule)

      if (kept === input.value) return

      // Se conserva la posición del cursor: sin esto, corregir un carácter en
      // medio de un importe salta el cursor al final en cada tecla.
      //
      // **Solo donde el navegador lo permite.** Un `input type="number"` no tiene
      // selección y pedírsela lanza una excepción, así que ahí se acepta que el
      // cursor salte al final — en un campo numérico el daño es menor, y el
      // navegador ya filtra las letras por su cuenta.
      const selectable = ['text', 'search', 'password', 'tel', 'url'].includes(input.type)
      const at = selectable ? (input.selectionStart ?? kept.length) : null
      const removed = input.value.length - kept.length

      input.value = kept

      if (at !== null) {
        const to = Math.max(0, at - removed)

        input.setSelectionRange(to, to)
      }

      // El `v-model` del componente escucha `input`: sin volver a emitirlo, la
      // pantalla mostraría el valor limpio y el modelo guardaría el sucio.
      input.dispatchEvent(new Event('input', { bubbles: true }))
    }

    input.addEventListener('input', handler)
    ;(element as HTMLElement & { _onlyHandler?: () => void })._onlyHandler = handler
  },

  unmounted(element) {
    const input = inputOf(element)
    const handler = (element as HTMLElement & { _onlyHandler?: () => void })._onlyHandler

    if (input && handler) input.removeEventListener('input', handler)
  }
}
