import { expect, test, type Page } from '@playwright/test'
import { activateTerminal } from './support/terminal'

/**
 * El plano del salón, desde la trastienda (F1-B, §10).
 *
 * La API de configuración existía desde el primer día y **nadie la llamaba**: el
 * plano se sembraba a mano en la base mientras el mensaje de "sin mesas" ya
 * remitía a una administración que no estaba construida.
 *
 * Lo que se prueba acá no es el formulario sino el **gesto**: que arrastrar deje
 * la mesa donde se soltó y que el servidor se entere. Un editor de plano falla
 * justo ahí —la mesa cae desplazada, o se ve bien hasta que alguien recarga— y
 * eso ninguna prueba unitaria lo ve.
 *
 * Precondición, como el resto de los guiones:
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DemoDataSeeder
 */

const TERMINAL = { branch: '001', terminal: 'CAJA-01', secret: 'terminal-dev' }
const OPERATOR = { code: 'ADMIN', pin: '1234' }

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

/** Arrastra un elemento soltándolo en un punto absoluto de la ventana. */
async function dragTo(page: Page, table: string, x: number, y: number) {
  const ficha = page.locator(`[data-plan-table="${table}"]`)
  const box = await ficha.boundingBox()

  if (!box) throw new Error(`sin ficha para ${table}`)

  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  await page.mouse.down()
  // Dos movimientos: con uno solo el navegador no siempre emite `pointermove`,
  // y el arrastre queda sin recorrido.
  await page.mouse.move(x - 40, y - 40, { steps: 6 })
  await page.mouse.move(x, y, { steps: 6 })
  await page.mouse.up()
}

test.describe('Plano del salón', () => {
  test.describe.configure({ mode: 'serial' })

  let page: Page

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage()
    await signIn(page)
  })

  test.afterAll(async () => {
    await page.context().close()
  })

  test('1 · la trastienda ofrece el plano', async () => {
    await page.goto('/admin/salon')

    // El menú lo prometía desde el principio y no llevaba a ninguna parte.
    await expect(page.getByRole('button', { name: /Mesa 1/ })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Nueva mesa' })).toBeVisible()
  })

  test('2 · arrastrar una mesa la deja donde se soltó, y el servidor se entera', async () => {
    await page.goto('/admin/salon')

    const ficha = page.locator('[data-plan-table="M1"]')

    // Tocarla la elige: si esto falla, el problema no es el arrastre sino que
    // la ficha no está recibiendo el puntero.
    await ficha.click()
    await expect(page.getByLabel('Código')).toBeVisible()

    const antes = await ficha.boundingBox()

    // A un hueco libre: el plano sembrado ocupa las cuatro esquinas de 40/200,
    // así que se baja en diagonal sin pisar a nadie. Soltarla encima de otra la
    // devolvería a su sitio, que es lo que el editor hace a propósito.
    await dragTo(page, 'M1', (antes?.x ?? 0) + 80, (antes?.y ?? 0) + 340)

    const despues = await ficha.boundingBox()
    expect(despues?.x).toBeGreaterThan(antes?.x ?? 0)

    // Lo que de verdad importa: que sobreviva a recargar. Un editor que solo
    // mueve píxeles en pantalla se descubre el día siguiente, con el plano
    // intacto y nadie sabiendo por qué.
    const movida = { x: despues?.x ?? 0, y: despues?.y ?? 0 }

    await page.reload()
    await expect(ficha).toBeVisible()

    const recargada = await ficha.boundingBox()

    expect(Math.abs((recargada?.x ?? 0) - movida.x)).toBeLessThan(4)
    expect(Math.abs((recargada?.y ?? 0) - movida.y)).toBeLessThan(4)

    // Se devuelve a su sitio: el salón es el mismo local para todos los
    // guiones, y dejarlo movido le rompe el plano al siguiente.
    await dragTo(page, 'M1', antes?.x ?? 0, antes?.y ?? 0)
  })

  test('2b · una mesa no se puede soltar encima de otra', async () => {
    await page.goto('/admin/salon')

    const origen = page.locator('[data-plan-table="M3"]')
    const destino = await page.locator('[data-plan-table="M4"]').boundingBox()
    const antes = await origen.boundingBox()

    await dragTo(page, 'M3', (destino?.x ?? 0) + 10, (destino?.y ?? 0) + 10)

    // Dos mesas superpuestas tapan la de abajo, y el mesero se queda sin poder
    // abrir su cuenta sin que nada explique por qué.
    await expect(page.getByText('Ahí ya hay una mesa')).toBeVisible()

    const despues = await origen.boundingBox()
    expect(Math.abs((despues?.x ?? 0) - (antes?.x ?? 0))).toBeLessThan(4)
  })

  test('3 · la posición queda ajustada a la rejilla', async () => {
    await page.goto('/admin/salon')

    // Dos mesas alineadas a ojo quedan alineadas de verdad: es lo que mantiene
    // el plano legible desde el otro lado del salón.
    const left = await page.locator('[data-plan-table="M1"]').evaluate(
      (node) => Number.parseInt((node as HTMLElement).style.left, 10)
    )

    expect(left % 20).toBe(0)
  })

  test('4 · una mesa nueva nace en el plano y se puede dar de baja', async () => {
    await page.goto('/admin/salon')

    await page.getByRole('button', { name: 'Nueva mesa' }).click()
    await page.getByLabel('Código').fill('M99')
    await page.getByLabel('Nombre').fill('Terraza 1')
    await page.getByRole('button', { name: 'Confirmar' }).click()

    const nueva = page.locator('[data-plan-table="M99"]')
    await expect(nueva).toBeVisible()

    await nueva.click()
    await page.getByRole('button', { name: 'Dar de baja la mesa' }).click()

    // Baja lógica: desaparece del plano y las ventas de ayer la siguen
    // referenciando.
    await expect(nueva).toHaveCount(0)
  })
})
