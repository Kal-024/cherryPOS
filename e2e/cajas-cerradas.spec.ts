import { expect, test, type Page } from '@playwright/test'
import { activateTerminal } from './support/terminal'

/**
 * Cuadrar una caja ya cerrada (H4.3).
 *
 * Al cerrar el turno aparecía el faltante o el sobrante y ahí terminaba todo: el
 * turno quedaba descuadrado para siempre y ninguna pantalla volvía a mostrarlo.
 * Eso pesa más allá del POS — el ERP no emite el comprobante contable del día si
 * la caja no cuadra.
 *
 * El guion cierra el cajón **contando de menos a propósito** y sigue el camino
 * completo: verlo en la bandeja, cuadrarlo con PIN de supervisor y comprobar que
 * el arqueo original sigue diciendo lo que se contó.
 *
 * Precondición, como el resto:
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DemoDataSeeder
 */

const TERMINAL = { branch: '001', terminal: 'CAJA-01', secret: 'terminal-dev' }
const OPERATOR = { code: 'ADMIN', pin: '1234', supervisorPin: '9876' }

async function signIn(page: Page) {
  await page.goto('/')

  await expect(page).toHaveURL(/\/terminal$/)
  await activateTerminal(page, TERMINAL)

  if (page.url().endsWith('/login')) {
    await page.getByLabel('Código de cajero').fill(OPERATOR.code)
    await page.getByLabel('PIN', { exact: true }).fill(OPERATOR.pin)
    await page.getByRole('button', { name: 'Entrar' }).click()
  }

  const search = page.getByPlaceholder('Buscar producto o escanear código')
  const openShift = page.getByRole('button', { name: 'Abrir turno' })

  await expect(search.or(openShift).first()).toBeVisible()

  for (let attempt = 0; attempt < 3 && await openShift.isVisible().catch(() => false); attempt++) {
    await page.getByLabel('Fondo inicial').fill('1000')
    await openShift.click({ timeout: 5_000 }).catch(() => undefined)
    await page.waitForTimeout(500)
  }

  await expect(search).toBeVisible()
}

test.describe('Cajas cerradas', () => {
  test.describe.configure({ mode: 'serial' })

  let page: Page

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage()
    await signIn(page)
  })

  test.afterAll(async () => {
    await page.context().close()
  })

  test('1 · cerrar el cajón contando de menos deja un faltante', async () => {
    await page.getByRole('button', { name: 'Cerrar turno' }).first().click()

    await expect(page.getByRole('heading', { name: /^Cerrar turno/ })).toBeVisible()

    // El fondo fue un billete de 1.000 y se cuentan nueve de 100: faltan 100.
    // Contar de menos es lo que pasa de verdad cuando alguien se equivoca al dar
    // un vuelto.
    // La primera es la de córdobas: el arqueo desglosa las dos monedas y el
    // billete de cien existe en ambas (D-06, Q-06).
    await page.getByRole('row', { name: /^100\.00/ }).first().getByRole('spinbutton').fill('9')
    await page.getByRole('button', { name: 'Cerrar turno' }).last().click()

    await expect(page.getByRole('heading', { name: /^Corte de turno/ })).toBeVisible()
  })

  test('2 · la caja aparece en la bandeja sin cuadrar', async () => {
    await page.goto('/admin/cajas')

    // Hasta que existió esta pantalla, un turno con faltante desaparecía de la
    // vista: el corte existía pero había que saber su identificador.
    await expect(page.getByText('Sin cuadrar').first()).toBeVisible()
    await expect(page.getByText(/caja\(s\) sin cuadrar/)).toBeVisible()
  })

  test('3 · el corte muestra esperado, contado y diferencia', async () => {
    await page.goto('/admin/cajas')

    await page.getByRole('button', { name: 'Ver el corte' }).first().click()

    await expect(page.getByText('Esperado, contado y diferencia')).toBeVisible()
    await expect(page.getByText('-100.00').first()).toBeVisible()
  })

  test('4 · cuadrar exige motivo y PIN de supervisor', async () => {
    await page.goto('/admin/cajas')

    await page.getByRole('button', { name: 'Cuadrar la caja' }).first().click()

    // El importe no se pide: es la diferencia que el corte calculó. Y se avisa
    // de que el arqueo no se toca, porque es la duda razonable de quien va a
    // firmar el ajuste.
    await expect(page.getByText('El arqueo no se modifica')).toBeVisible()

    await page.getByLabel('Motivo').fill('Vuelto entregado de más')
    await page.getByLabel('Código de supervisor').fill(OPERATOR.code)
    await page.getByLabel('PIN de autorización').fill(OPERATOR.supervisorPin)

    await page.getByRole('button', { name: 'Cuadrar la caja' }).last().click()

    await expect(page.getByText('Caja cuadrada').first()).toBeVisible()
  })

  test('5 · queda cuadrada, y el ajuste sigue a la vista', async () => {
    await page.goto('/admin/cajas')

    await expect(page.getByText('Todas las cajas cuadradas')).toBeVisible()
    await expect(page.getByText('Cuadrada').first()).toBeVisible()

    await page.getByRole('button', { name: 'Ver el corte' }).first().click()

    // Un descuadre saldado se sigue viendo: esa es la diferencia entre cuadrar y
    // tapar. Y la diferencia ya es cero porque el movimiento la cubre, no porque
    // alguien la haya tachado.
    await expect(page.getByText('Ajustes asentados')).toBeVisible()
    await expect(page.getByText('Vuelto entregado de más')).toBeVisible()
    await expect(page.getByText('0.00').first()).toBeVisible()
  })
})
