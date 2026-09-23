import { expect, type Page } from '@playwright/test'

/**
 * Activar la terminal, aguantando el freno de fuerza bruta.
 *
 * El servidor admite **diez activaciones por minuto** (B-15), y con razón: la
 * credencial de terminal es la puerta del local y probarla a ciegas tiene que
 * costar. Pero cada archivo de guion arranca con un navegador limpio y activa de
 * nuevo, así que la suite entera consume ese cupo entre todos. Al sumar dos
 * guiones más, el último empezó a encontrarse con *"Demasiados intentos
 * seguidos"* y fallaba por algo que no estaba probando.
 *
 * Esperar y reintentar no es maquillaje: es lo que hace una persona cuando ve
 * ese mensaje, y el propio aviso lo dice. Lo que **no** se hace es subir el tope
 * ni desactivarlo en pruebas, porque entonces la protección dejaría de estar
 * probada justo donde importa.
 */

const THROTTLE_HINT = 'Demasiados intentos'
const WAIT_MS = 21_000

export interface TerminalCredentials {
  branch: string
  terminal: string
  secret: string
}

export async function activateTerminal(
  page: Page,
  credentials: TerminalCredentials,
  attempts = 3
): Promise<void> {
  for (let attempt = 1; attempt <= attempts; attempt++) {
    await page.getByLabel('Código de sucursal').fill(credentials.branch)
    await page.getByLabel('Código de terminal').fill(credentials.terminal)
    await page.getByLabel('Secreto de la terminal').fill(credentials.secret)
    await page.getByRole('button', { name: 'Activar terminal' }).click()

    // O se salió de la pantalla de activación, o el servidor pidió esperar.
    const throttled = page.getByText(THROTTLE_HINT)

    await expect(async () => {
      const stillHere = page.url().endsWith('/terminal')
      const blocked = await throttled.isVisible().catch(() => false)

      expect(stillHere && !blocked).toBe(false)
    }).toPass({ timeout: 15_000 })

    if (!page.url().endsWith('/terminal')) return

    if (attempt < attempts) {
      // El aviso dice veinte segundos; se espera uno más por el reloj del
      // servidor.
      await page.waitForTimeout(WAIT_MS)
    }
  }

  await expect(page).not.toHaveURL(/\/terminal$/)
}

/**
 * Busca un producto por su nombre y lo agrega.
 *
 * Teclea y confirma **sin esperar nada**, que es lo que hace un cajero apurado
 * con un cliente delante. Antes eso fallaba en una caja recién abierta: la
 * búsqueda es local sobre el catálogo en IndexedDB (D-04) y, con la caché
 * todavía bajando, la caja trataba el texto como código de barras y contestaba
 * que el producto no existía.
 *
 * Ahora el propio campo espera a que el catálogo llegue antes de dar nada por
 * inexistente, así que el guion puede volver a hacer el gesto de siempre — y si
 * alguna vez deja de poder, es que la caja volvió a mentir.
 */
export async function addByName(page: Page, query: string): Promise<void> {
  const search = page.getByPlaceholder('Buscar producto o escanear código')

  await search.fill(query)
  await search.press('Enter')
}
