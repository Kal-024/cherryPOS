import { afterEach, describe, expect, it, vi } from 'vitest'
import { useImports } from './useImports'

/**
 * La subida del archivo y el flujo de tres pasos (D-15).
 *
 * Dos cosas que se rompen en silencio y por eso se fijan acá: que el archivo
 * viaje como `FormData` **sin** Content-Type propio —el navegador tiene que
 * ponerlo con su `boundary`, y forzarlo a JSON convierte la subida en un 422
 * incomprensible— y que previsualizar no toque nada, porque es el paso que
 * convierte "subí el archivo y rezá" en "mirá qué va a pasar".
 */

function stub(data: unknown) {
  const sent: { url: string, method?: string, body: unknown, headers: Record<string, string> }[] = []

  vi.stubGlobal('fetch', (input: string, init: RequestInit = {}) => {
    sent.push({
      url: String(input),
      method: init.method,
      body: init.body,
      headers: (init.headers ?? {}) as Record<string, string>
    })

    return Promise.resolve({
      ok: true,
      status: 200,
      json: () => Promise.resolve({ message: '', data, status: 200 })
    } as Response)
  })

  return sent
}

const batch = {
  id: 'b1',
  kind: 'products',
  filename: 'productos.xlsx',
  status: 'previewed',
  rows_total: 3,
  rows_valid: 2,
  rows_invalid: 1,
  rows_applied: 0,
  applied_at: null,
  reverted_at: null
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('importación desde Excel', () => {
  it('el archivo viaja como FormData y sin Content-Type propio', async () => {
    const sent = stub(batch)
    const file = new File(['x'], 'productos.xlsx')

    await useImports().preview('products', file)

    expect(sent[0]!.url).toContain('/imports/products/preview')
    expect(sent[0]!.body).toBeInstanceOf(FormData)
    expect(sent[0]!.headers['Content-Type']).toBeUndefined()
  })

  it('previsualizar deja el lote a la vista sin aplicarlo', async () => {
    stub(batch)
    const imports = useImports()

    await imports.preview('products', new File(['x'], 'productos.xlsx'))

    expect(imports.batch.value?.status).toBe('previewed')
    expect(imports.batch.value?.rows_applied).toBe(0)
  })

  it('aplicar y deshacer operan sobre el lote previsualizado', async () => {
    stub(batch)
    const imports = useImports()
    await imports.preview('products', new File(['x'], 'productos.xlsx'))

    const sent = stub({ ...batch, status: 'applied', rows_applied: 2 })
    await imports.apply()

    expect(sent[0]!.url).toContain('/imports/b1/apply')
    expect(imports.batch.value?.status).toBe('applied')

    const reverting = stub({ ...batch, status: 'reverted' })
    await imports.revert()

    expect(reverting[0]!.url).toContain('/imports/b1/revert')
    expect(imports.batch.value?.status).toBe('reverted')
  })

  it('sin lote previsualizado no se aplica nada', async () => {
    const sent = stub(batch)
    const imports = useImports()

    await imports.apply()

    // Aplicar sin haber mirado es justo lo que el flujo de tres pasos existe
    // para impedir.
    expect(sent).toHaveLength(0)
  })
})
