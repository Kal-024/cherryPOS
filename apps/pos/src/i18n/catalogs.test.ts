import { readFileSync, readdirSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import en from './locales/en'
import es from './locales/es'

/**
 * Paridad de catálogos.
 *
 * P6 dice que ningún literal vive en el código; lo que esta prueba impide es el
 * fallo siguiente — que una clave exista en español y no en inglés. Sin ella la
 * ausencia no se nota hasta que un usuario en inglés ve la clave cruda en
 * pantalla, y en una caja eso ocurre delante de un cliente.
 */

type Catalog = Record<string, unknown>

function flatten(catalog: Catalog, prefix = ''): string[] {
  return Object.entries(catalog).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key

    return value !== null && typeof value === 'object'
      ? flatten(value as Catalog, path)
      : [path]
  })
}

const spanish = flatten(es).sort()
const english = flatten(en).sort()

describe('catálogos de traducción', () => {
  it('el catálogo en español no está vacío', () => {
    expect(spanish.length).toBeGreaterThan(20)
  })

  it('inglés y español declaran exactamente las mismas claves', () => {
    expect(english).toEqual(spanish)
  })

  it('ninguna traducción queda en blanco', () => {
    const blanks = [
      ...findBlanks(es as Catalog, 'es'),
      ...findBlanks(en as Catalog, 'en')
    ]

    expect(blanks).toEqual([])
  })
})

/**
 * Toda clave que el código invoca tiene que existir.
 *
 * La paridad entre idiomas no basta: una clave que nadie declaró está ausente en
 * los dos catálogos por igual, y vue-i18n la imprime **cruda en pantalla**. En
 * una caja eso ocurre delante de un cliente.
 */
function sourceFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)

    if (entry.isDirectory()) return sourceFiles(path)

    return /\.(vue|ts)$/.test(entry.name) && !entry.name.endsWith('.test.ts') ? [path] : []
  })
}

describe('claves referenciadas', () => {
  it('toda clave usada en el código existe en el catálogo', () => {
    const root = join(dirname(fileURLToPath(import.meta.url)), '..')
    const declared = new Set(flatten(es as Catalog))
    const missing = new Set<string>()

    for (const file of sourceFiles(root)) {
      const source = readFileSync(file, 'utf8')

      // El límite por la izquierda importa: sin él, `emit('closed')` entra
      // como si fuera una clave, porque `emit(` termina en `t(`. `vt(` sí entra
      // a propósito: es el traductor del vocabulario por perfil (A-04) y sus
      // claves tienen que existir igual que las demás.
      for (const match of source.matchAll(/(?<![\w$])v?\$?t\(\s*'([\w.]+)'/g)) {
        const key = match[1]!

        // Las claves compuestas en tiempo de ejecución —`payment.${método}`— no
        // se pueden verificar así, y se declaran enteras en el catálogo.
        if (!declared.has(key)) missing.add(`${key} (${file.split('/src/')[1]})`)
      }
    }

    expect([...missing]).toEqual([])
  })
})

function findBlanks(catalog: Catalog, locale: string, prefix = ''): string[] {
  return Object.entries(catalog).flatMap(([key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key

    if (value !== null && typeof value === 'object') {
      return findBlanks(value as Catalog, locale, path)
    }

    return typeof value === 'string' && value.trim() === '' ? [`${locale}:${path}`] : []
  })
}

/**
 * El vocabulario por perfil (A-04, D-01).
 *
 * El mapa de `useVocabulary` traduce **clave a clave** dentro del mismo
 * catálogo. Si el destino no existe, vue-i18n imprime la clave cruda, y ocurre
 * solo en los locales con ese perfil: nadie lo vería hasta que un restaurante
 * abriera su primera cuenta.
 */
describe('vocabulario por perfil de negocio', () => {
  it('toda clave del perfil existe en el catálogo', () => {
    const root = join(dirname(fileURLToPath(import.meta.url)), '..')
    const source = readFileSync(join(root, 'composables/useVocabulary.ts'), 'utf8')
    const declared = new Set(flatten(es as Catalog))
    const missing: string[] = []

    for (const match of source.matchAll(/'([\w.]+)':\s*'([\w.]+)'/g)) {
      const [, generic, specific] = match

      if (!declared.has(generic!)) missing.push(`origen ${generic}`)
      if (!declared.has(specific!)) missing.push(`destino ${specific}`)
    }

    expect(missing).toEqual([])
  })
})
