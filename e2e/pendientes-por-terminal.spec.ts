import { expect, test, type Page } from '@playwright/test'
import { activateTerminal } from './support/terminal'

/**
 * Cambiar de caja sin perder lo vendido sin conexión (H6.4, D-05).
 *
 * El recorrido es el de un local de verdad: el mostrador se queda sin red y
 * vende igual, el equipo tiene que atender el salón un rato, y después vuelve a
 * ser el mostrador. Lo vendido durante el corte **no puede evaporarse en el
 * camino** — es plata cobrada y todavía no registrada.
 *
 * Y no puede enviarlo otra caja: el servidor atribuye la venta según el token, y
 * el número sale de la serie reservada a la terminal que lo emitió. Por eso lo
 * guardado lleva dueño y por eso la pantalla de activación lo muestra.
 *
 * Precondición, como los demás guiones: `pnpm e2e:seed`.
 */

const SECRET = 'terminal-dev'
const OPERATOR = { code: 'ADMIN', pin: '1234' }

async function activar(page: Page, terminal: string) {
  await expect(page).toHaveURL(/\/terminal$/)
  await activateTerminal(page, { branch: '001', terminal, secret: SECRET })

  if (page.url().endsWith('/login')) {
    await page.getByLabel('Código de cajero').fill(OPERATOR.code)
    await page.getByLabel('PIN', { exact: true }).fill(OPERATOR.pin)
    await page.getByRole('button', { name: 'Entrar' }).click()
  }
}

/**
 * Abre el diálogo de cambio y confirma.
 *
 * Se reintenta abrirlo porque la tarjeta del PIN se redibuja al terminar de leer
 * la bandeja —el aviso de pendientes aparece ahí— y el primer clic puede caer
 * sobre el botón que ya no está. No es un defecto de la pantalla: es una carrera
 * de la prueba contra una lectura de disco.
 */
async function cambiarTerminal(page: Page) {
  const confirmar = page.getByRole('button', { name: 'Desactivar y cambiar' })

  for (let intento = 0; intento < 3; intento++) {
    await page.getByRole('button', { name: 'Cambiar de terminal' }).click({ force: true })

    if (await confirmar.isVisible({ timeout: 2_000 }).catch(() => false)) break
  }

  await confirmar.click({ force: true })
  await expect(page).toHaveURL(/\/terminal$/)
}

async function irAlPin(page: Page) {
  const salir = page.getByRole('button', { name: 'Salir' })

  // Con `force`: la cabecera se redibuja cada dos segundos por el sondeo —el
  // código de turno, el estado de conexión— y el botón se corre unos píxeles.
  // Playwright espera a que deje de moverse y esa quietud no llega nunca.
  if (await salir.isVisible().catch(() => false)) await salir.click({ force: true })

  await expect(page).toHaveURL(/\/login$/)
}

test('lo vendido sin red sobrevive a pasar por otra terminal', async ({ page }) => {
  await page.goto('/')
  await activar(page, 'CAJA-01')

  const search = page.getByPlaceholder('Buscar producto o escanear código')
  const abrir = page.getByRole('button', { name: 'Abrir turno' })
  await expect(search.or(abrir).first()).toBeVisible()

  // El panel de turno se redibuja al terminar de leer el estado del servidor y
  // el botón puede desprenderse del DOM en medio del clic. Se reintenta, como en
  // el guion del salón, en vez de dar por fallado el recorrido por una carrera
  // de la pantalla.
  for (let intento = 0; intento < 3 && await abrir.isVisible().catch(() => false); intento++) {
    await page.getByLabel('Fondo inicial').fill('1000')
    await abrir.click({ timeout: 5_000 }).catch(() => undefined)
    await page.waitForTimeout(500)
  }

  await expect(search).toBeVisible()

  // El catálogo local tiene que estar bajado **antes** del corte: lo que no se
  // bajó con red no se va a poder buscar durante (H6). Se comprueba buscando.
  await search.fill('Gaseosa')
  await expect(page.getByText('DEMO-001')).toBeVisible()
  await search.press('Escape')

  // Se corta la red del terminal, como en el paso 5 de la compuerta: para la
  // caja es lo mismo que el servidor apagado.
  await page.route('**/api/**', (route) => route.abort())
  await expect(page.getByText('Modo degradado')).toBeVisible()

  await search.fill('7501234567890')
  await search.press('Enter')
  await expect(page.getByText('Gaseosa 1.5 L')).toBeVisible()

  await page.getByRole('button', { name: /^Cobrar/ }).click()
  await page.getByRole('button', { name: /^Exacto/ }).click()
  await page.getByRole('button', { name: 'Cerrar venta' }).click()

  // Un ticket esperando en la bandeja de esta terminal.
  await expect(page.getByText(/Sin enviar: 1/)).toBeVisible()

  await page.unroute('**/api/**')
  await irAlPin(page)

  // El aviso nombra la terminal y **deja pasar**: quedarse atrapado sin red es
  // peor que cambiar sabiendo lo que queda atrás.
  await page.getByRole('button', { name: 'Cambiar de terminal' }).click({ force: true })
  await expect(page.getByText(/Quedan 1 ticket\(s\) de CAJA-01/)).toBeVisible()
  await page.getByRole('button', { name: 'Desactivar y cambiar' }).click({ force: true })

  // La pantalla de activación recuerda lo que quedó esperando en el equipo.
  await expect(page).toHaveURL(/\/terminal$/)
  await expect(page.getByText('En este equipo quedan tickets sin enviar:')).toBeVisible()

  await activar(page, 'SALON-01')

  // El salón no ve la bandeja del mostrador: no es suya y no podría enviarla.
  await expect(page.getByText(/Sin enviar:/)).toBeHidden()

  await irAlPin(page)
  await cambiarTerminal(page)

  await activar(page, 'CAJA-01')

  // De vuelta en casa: el ticket se envía solo al haber red, y el servidor no lo
  // duplica porque su `uuid` ya viajó una sola vez.
  await expect(page.getByText(/Sin enviar:/)).toBeHidden({ timeout: 20_000 })
})
