import { expect, test, type Page } from '@playwright/test'
import { activateTerminal } from './support/terminal'

/**
 * La lista 86: lo que se acabó hoy (F1-B).
 *
 * Del argot de cocina —*«86 the salmon»*, dejen de venderlo—. Hasta ahora la
 * única herramienta era desactivar el producto, que es **baja de catálogo**: lo
 * saca de reportes e importaciones y alguien tiene que acordarse de reactivarlo.
 *
 * Lo que se prueba acá es el recorrido completo entre dos pantallas, que es donde
 * un mecanismo así falla: se marca en la trastienda y **la caja tiene que
 * enterarse**. El terminal busca en su catálogo cacheado (D-04) y la
 * disponibilidad viaja aparte, por sondeo; si esa pieza no se conecta, el plato
 * marcado sigue apareciendo normal hasta que alguien recargue.
 *
 * Precondición, como el resto de los guiones:
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DemoDataSeeder
 */

const TERMINAL = { branch: '001', terminal: 'CAJA-01', secret: 'terminal-dev' }
const OPERATOR = { code: 'ADMIN', pin: '1234' }

/** El producto que se va a agotar. Vive en los datos de demostración. */
const PRODUCT = 'Gaseosa'

async function signIn(page: Page) {
  await page.goto('/')

  await expect(page).toHaveURL(/\/terminal$/)
  await activateTerminal(page, TERMINAL)

  if (page.url().endsWith('/login')) {
    await page.getByLabel('Código de cajero').fill(OPERATOR.code)
    await page.getByLabel('PIN', { exact: true }).fill(OPERATOR.pin)
    await page.getByRole('button', { name: 'Entrar' }).click()
  }

  await expect(
    page.getByPlaceholder('Buscar producto o escanear código')
      .or(page.getByRole('button', { name: 'Abrir turno' }))
      .first()
  ).toBeVisible()
}

/** Marca o repone desde la trastienda, que es donde vive el permiso. */
async function toggle(page: Page, label: string) {
  await page.goto('/admin/productos')

  const row = page.locator('tr').filter({ hasText: PRODUCT }).first()

  await expect(row).toBeVisible()
  await row.getByRole('button', { name: label }).click()
}

test.describe('Lista 86', () => {
  test.describe.configure({ mode: 'serial' })

  let page: Page

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage()
    await signIn(page)
  })

  test.afterAll(async () => {
    // Se repone pase lo que pase: el catálogo es el mismo local para los
    // guiones que siguen, y dejarlo agotado le rompe la venta al próximo.
    await toggle(page, 'Volvió a haber').catch(() => undefined)
    await page.context().close()
  })

  test('1 · marcar agotado no da de baja el producto', async () => {
    await toggle(page, 'Marcar agotado hoy')

    const row = page.locator('tr').filter({ hasText: PRODUCT }).first()

    // Sigue en la lista, con su precio: no es lo mismo que archivarlo.
    await expect(row).toContainText('Agotado')
    await expect(row).not.toContainText('Dado de baja')
  })

  test('2 · la caja lo muestra agotado y no lo deja agregar', async () => {
    await page.goto('/')

    const search = page.getByPlaceholder('Buscar producto o escanear código')

    await search.fill(PRODUCT)

    const suggestion = page.locator('button').filter({ hasText: PRODUCT }).first()

    await expect(suggestion).toBeVisible()
    // Rotulado, no solo gris: en una pantalla con reflejo el gris se lee como
    // "todavía cargando".
    await expect(suggestion).toContainText('Agotado')

    await suggestion.click()

    // No entró ninguna línea: el carrito sigue vacío.
    await expect(page.getByText('Sin líneas todavía')).toBeVisible()
  })

  test('3 · reponerlo lo devuelve a la venta', async () => {
    await toggle(page, 'Volvió a haber')

    await page.goto('/')

    const search = page.getByPlaceholder('Buscar producto o escanear código')

    await search.fill(PRODUCT)

    const suggestion = page.locator('button').filter({ hasText: PRODUCT }).first()

    await expect(suggestion).not.toContainText('Agotado')

    await suggestion.click()

    await expect(page.getByText('Sin líneas todavía')).toBeHidden()
  })
})
