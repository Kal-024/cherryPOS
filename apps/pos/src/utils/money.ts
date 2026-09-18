/**
 * Formato de importes para la pantalla.
 *
 * **Nada de aritmética.** Los totales los calcula el servidor con el motor de
 * `packages/calc`, y el terminal solo los muestra: sumar acá abriría una tercera
 * implementación de las fórmulas, que es exactamente lo que el proyecto decidió
 * no tener.
 *
 * Lo único que se calcula localmente es el vuelto tentativo mientras el cajero
 * teclea, y aun ese se recalcula en el servidor al registrar el pago.
 */
import { formatLocale } from '../i18n'

export function money(value: string | number | null | undefined, currency = 'NIO'): string {
  if (value === null || value === undefined || value === '') return ''

  const amount = typeof value === 'number' ? value : Number.parseFloat(value)

  if (Number.isNaN(amount)) return String(value)

  return new Intl.NumberFormat(formatLocale.value, {
    style: 'currency',
    currency,
    minimumFractionDigits: 2
  }).format(amount)
}

/** Sin símbolo: para columnas donde la moneda ya está en el encabezado. */
export function amount(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''

  const parsed = typeof value === 'number' ? value : Number.parseFloat(value)

  if (Number.isNaN(parsed)) return String(value)

  return new Intl.NumberFormat(formatLocale.value, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(parsed)
}

/** `2.0000` se lee como `2`; `0.8470` como `0.847`. */
export function quantity(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return ''

  const text = String(value)

  return text.includes('.') ? text.replace(/0+$/, '').replace(/\.$/, '') : text
}
