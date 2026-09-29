import { readdirSync, readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Ningún `U*` inventado en toda la interfaz.
 *
 * Un componente que la biblioteca no exporta **no falla**: Vue lo deja pasar
 * como etiqueta desconocida y el navegador la dibuja como elemento en línea. El
 * caso real fue `UButtonGroup` —que no existe en Nuxt UI 4, donde el grupo se
 * llama `UFieldGroup`— en los controles de zoom del plano del salón: los tres
 * botones se alineaban por la línea base del texto y el del porcentaje quedaba
 * montado más abajo que sus vecinos de ícono.
 *
 * Es la clase de error que solo se ve mirando la pantalla, y solo si se sabe
 * qué mirar. Esta prueba la ve sola.
 */

const here = dirname(fileURLToPath(import.meta.url))
const src = join(here, '..')

/** Todos los `.vue` del proyecto. */
function sources(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)

    if (entry.isDirectory()) return sources(path)

    return entry.isFile() && entry.name.endsWith('.vue') ? [path] : []
  })
}

/** Los `U*` que la biblioteca sí exporta, por nombre de archivo. */
function available(): Set<string> {
  // Se pregunta por un componente conocido porque el paquete no publica su
  // `package.json`: el mapa de exportación solo abre `./components/*`.
  const require = createRequire(import.meta.url)
  const components = dirname(require.resolve('@nuxt/ui/components/Button.vue'))
  const folders = [
    components,
    join(components, 'content'),
    join(components, 'color-mode'),
    join(dirname(components), 'vue/components')
  ]

  const names = folders.flatMap((folder) => {
    try {
      return readdirSync(folder).filter((file) => file.endsWith('.vue'))
    } catch {
      return []
    }
  })

  return new Set(names.map((file) => `U${file.replace(/\.vue$/, '')}`))
}

describe('componentes de Nuxt UI', () => {
  it('cada `U*` usado en una plantilla existe en la biblioteca', () => {
    const exported = available()
    const missing: string[] = []

    for (const file of sources(src)) {
      const template = readFileSync(file, 'utf8')

      for (const [, tag] of template.matchAll(/<(U[A-Z][A-Za-z]*)[\s/>]/g)) {
        if (!exported.has(tag)) missing.push(`${file.slice(src.length + 1)}: <${tag}>`)
      }
    }

    expect(missing).toEqual([])
  })
})
