import { describe, expect, it } from 'vitest'
import { uuid7 } from './uuid'

describe('uuid7', () => {
  it('tiene la forma de un UUID versión 7', () => {
    const value = uuid7()

    expect(value).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
    )
  })

  it('ordena por momento de creación', async () => {
    const first = uuid7()
    await new Promise((resolve) => setTimeout(resolve, 3))
    const second = uuid7()

    // Es lo que compra v7 sobre v4: ordenar sin columna extra y no fragmentar
    // el índice de una tabla que crece un ticket por minuto.
    expect(second > first).toBe(true)
  })

  it('no se repite', () => {
    const generated = new Set(Array.from({ length: 500 }, () => uuid7()))

    expect(generated.size).toBe(500)
  })
})
