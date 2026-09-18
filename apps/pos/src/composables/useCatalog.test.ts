import { describe, expect, it } from 'vitest'

/**
 * El orden de la búsqueda local (D-04).
 *
 * Se prueba la regla de puntuación aislada porque es la que decide qué ve el
 * cajero primero, y es donde una mejora bienintencionada rompe el flujo: un
 * producto muy vendido **no** puede ganarle a la coincidencia exacta del código
 * que el lector acaba de leer.
 */

function normalize(value: string): string {
  return value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim()
}

function score(
  entry: { text: string, sku: string, codes: string[] },
  term: string,
  uses: number
): number | null {
  const needle = normalize(term)
  let base: number

  if (entry.codes.includes(term.trim())) base = 1000
  else if (entry.sku === needle) base = 900
  else if (entry.sku.startsWith(needle)) base = 700
  else if (entry.text.startsWith(needle)) base = 500
  else if (entry.text.includes(needle)) base = 300
  else return null

  return base + Math.min(uses, 99)
}

const cheese = { text: normalize('Queso seco'), sku: 'queso', codes: ['7501234567890'] }
const cola = { text: normalize('Gaseosa cola 1.5 L'), sku: 'gas-15', codes: ['7509999999999'] }

describe('orden de la búsqueda local', () => {
  it('el código leído gana sobre cualquier otra cosa', () => {
    const exact = score(cheese, '7501234567890', 0)!
    const popular = score(cola, 'gas', 99)!

    expect(exact).toBeGreaterThan(popular)
  })

  it('lo que empieza con el término va antes que lo que solo lo contiene', () => {
    const starts = score({ text: normalize('Cola 1 L'), sku: 'x', codes: [] }, 'cola', 0)!
    const contains = score(cola, 'cola', 0)!

    expect(starts).toBeGreaterThan(contains)
  })

  it('la frecuencia desempata dentro del grupo, no entre grupos', () => {
    const usado = score(cola, 'gaseosa', 99)!
    const nuevo = score({ text: normalize('Gaseosa naranja'), sku: 'y', codes: [] }, 'gaseosa', 0)!

    expect(usado).toBeGreaterThan(nuevo)

    // Pero sigue perdiendo contra una coincidencia de SKU, que es más precisa.
    expect(score(cheese, 'queso', 0)!).toBeGreaterThan(usado)
  })

  it('las tildes no impiden encontrar', () => {
    const entry = { text: normalize('Piñón criollo'), sku: 'pin', codes: [] }

    expect(score(entry, 'pinon', 0)).not.toBeNull()
    expect(score(entry, 'piñón', 0)).not.toBeNull()
  })

  it('lo que no coincide queda fuera', () => {
    expect(score(cheese, 'martillo', 0)).toBeNull()
  })
})
