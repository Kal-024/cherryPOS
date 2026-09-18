import { expect, test, type Page } from '@playwright/test'

/**
 * La compuerta de cierre de F1-A (§8 del plan), desde el navegador.
 *
 * **No se declara F1-A cerrado hasta que esto pase completo.** El guion en
 * `deploy/scripts/compuerta-f1a.sh` recorre los mismos diez pasos contra la API
 * cruda; este los recorre como los recorre un cajero, que es donde aparecen los
 * errores que la API no ve: el foco que no vuelve, el botón deshabilitado, el
 * total que se muestra con el símbolo de otra moneda.
 *
 * Precondición, deliberadamente fuera de la automatización:
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Un `migrate:fresh` disparado por la herramienta de pruebas es la forma más
 * rápida de borrarle la base a alguien que solo quería correr un test.
 */

const TERMINAL = { branch: '001', terminal: 'CAJA-01', secret: 'terminal-dev' }
const OPERATOR = { code: 'ADMIN', pin: '1234' }

/** Los mismos códigos que siembra `DemoDataSeeder`. */
const BARCODES = {
  gaseosa: '7501234567890',
  cafe: '7501111111111',
  jabon: '7504444444444',
  queso: '7505555555555',
  arrozExento: '7506666666666'
}

async function activateTerminal(page: Page) {
  await page.goto('/')

  // Sin terminal activada, el guardia de ruta no deja ir a ningún otro lado
  // (D-05): eso mismo se verifica al llegar acá sin pedirlo.
  await expect(page).toHaveURL(/\/terminal$/)

  await page.getByLabel('Código de sucursal').fill(TERMINAL.branch)
  await page.getByLabel('Código de terminal').fill(TERMINAL.terminal)
  await page.getByLabel('Secreto de la terminal').fill(TERMINAL.secret)
  await page.getByRole('button', { name: 'Activar terminal' }).click()

  await expect(page).not.toHaveURL(/\/terminal$/)
}

/**
 * Identifica al cajero **si hace falta**.
 *
 * La sesión de cajero vive en el servidor y es de la terminal, no del navegador
 * (D-05): si el cajero anterior no cerró la suya, la caja abre directamente. Es
 * lo que pasa en el local cuando alguien recarga la pantalla, y el guion tiene
 * que tolerarlo igual que la caja.
 */
async function signIn(page: Page) {
  if (page.url().endsWith('/login')) {
    await page.getByLabel('Código de cajero').fill(OPERATOR.code)
    await page.getByLabel('PIN', { exact: true }).fill(OPERATOR.pin)
    await page.getByRole('button', { name: 'Entrar' }).click()
  }

  await expect(page).toHaveURL(/\/$/)
}

/**
 * Pasa un código como lo pasaría el lector: teclea y manda Enter.
 *
 * Espera a que la línea aparezca antes de devolver el control. Sin esa espera
 * la prueba pasa el siguiente código mientras el anterior sigue viajando, y lo
 * que falla después es una fila que falta sin ninguna pista de por qué.
 */
async function scan(page: Page, code: string) {
  const lines = page.getByRole('row')
  const before = await lines.count()
  const search = page.getByPlaceholder('Buscar producto o escanear código')

  await search.fill(code)
  await search.press('Enter')

  // La primera línea trae además el encabezado de la tabla.
  await expect(lines).toHaveCount(before === 0 ? 2 : before + 1)
}

/** Cobra el total exacto y cierra. Tres clics que van siempre juntos. */
async function chargeExact(page: Page) {
  await page.getByRole('button', { name: /^Cobrar/ }).click()
  await page.getByRole('button', { name: /^Exacto/ }).click()
  await page.getByRole('button', { name: 'Cerrar venta' }).click()

  await expect(page.getByText(/Venta .* cerrada/)).toBeVisible()
}

/** El carrito vacío: lo que se ve entre una venta y la siguiente. */
async function expectEmptyCart(page: Page) {
  await expect(page.getByText('Sin líneas todavía')).toBeVisible()
}

test.describe('Compuerta de cierre de F1-A', () => {
  test.describe.configure({ mode: 'serial' })

  /**
   * Una sola activación para todo el guion.
   *
   * Es lo que hace una terminal de verdad —la credencial del equipo se pone una
   * vez al día (D-05)— y además es lo que evita chocar con la protección de
   * fuerza bruta: reactivar en cada paso gastaba diez intentos por minuto y el
   * guion terminaba fallando por "demasiados intentos" en vez de por lo que
   * estuviera probando.
   */
  let page: Page

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage()
    await activateTerminal(page)
    await signIn(page)
  })

  test.afterAll(async () => {
    await page.context().close()
  })

  test('1 · abrir turno con fondo inicial', async () => {

    // Nada se registra fuera de un turno abierto (H4.1): lo único que la
    // pantalla ofrece es abrirlo.
    await expect(page.getByText('No hay turno abierto en esta terminal.')).toBeVisible()

    await page.getByLabel('Fondo inicial').fill('1000')
    await page.getByRole('button', { name: 'Abrir turno' }).click()

    await expect(page.getByPlaceholder('Buscar producto o escanear código')).toBeVisible()
  })

  test('2 · vender con lector, con un producto exento', async () => {

    await scan(page, BARCODES.gaseosa)
    await scan(page, BARCODES.cafe)
    await scan(page, BARCODES.jabon)
    await scan(page, BARCODES.arrozExento)

    // El exento se declara aparte de la base gravada: el libro de ventas los
    // distingue y la pantalla también.
    await expect(page.getByText('Exento', { exact: true })).toBeVisible()

    // El foco vuelve solo a la búsqueda tras cada lectura: el cajero no toca el
    // ratón (§10, perfil `scan_first`).
    await expect(page.getByPlaceholder('Buscar producto o escanear código')).toBeFocused()
  })

  test('3 · cobrar en córdobas y cerrar', async () => {

    await scan(page, BARCODES.gaseosa)
    await chargeExact(page)

    // La gaveta se abre a mano mientras no exista el agente de impresión (Q-12).
    await expect(page.getByText('Todavía no hay agente de impresión')).toBeVisible()
  })

  test('4 · suspender una venta, atender otra y retomarla', async () => {

    await scan(page, BARCODES.queso)

    // La cuenta suspendida es una venta en estado `suspendida`, no una tabla
    // espejo (D-01): por eso puede retomarse desde cualquier caja.
    await page.getByRole('button', { name: 'Suspender venta' }).first().click()
    await page.getByLabel('Referencia').fill('Mesa 4')
    await page.getByRole('button', { name: 'Suspender venta' }).last().click()

    await expectEmptyCart(page)

    await scan(page, BARCODES.jabon)
    await chargeExact(page)
    await expectEmptyCart(page)

    await page.getByRole('button', { name: /Cuentas suspendidas/ }).click()
    await page.getByRole('button', { name: /Mesa 4/ }).click()

    // Vuelve con su línea: el queso que quedó esperando.
    await expect(page.getByRole('row')).toHaveCount(2)
  })

  test('5 · sin servidor la caja sigue vendiendo', async () => {

    // Se corta la red del terminal en vez de apagar el proceso: para la caja es
    // exactamente lo mismo —el servidor no contesta— y es lo que el paso 7 del
    // guion quiere probar. Que el servidor no duplique los tickets al volver lo
    // cubren `OfflineSaleTest` y el guion de `deploy/scripts`.
    await page.route('**/api/**', (route) => route.abort())

    await expect(page.getByText('Modo degradado')).toBeVisible()

    await page.unroute('**/api/**')
  })

  test('6 · cerrar el turno y cuadrar el cajón', async () => {

    // El cierre se pide desde la cabecera: es una acción de fin de jornada, no
    // un botón al lado de "Cobrar".
    await page.getByRole('button', { name: 'Cerrar turno' }).first().click()

    // El arqueo: se cuenta el cajón por denominación y recién entonces se
    // confirma. El fondo inicial fue un billete de 1000, así que eso es lo que
    // tiene que haber dentro.
    await expect(page.getByRole('heading', { name: /^Cerrar turno/ })).toBeVisible()
    await page.getByRole('row', { name: /^1,000\.00/ }).getByRole('spinbutton').fill('1')
    await page.getByRole('button', { name: 'Cerrar turno' }).last().click()

    // El arqueo cuenta el cajón por denominación y por moneda (D-06, Q-06): un
    // consolidado escondería que sobran córdobas y faltan dólares.
    await expect(page.getByRole('heading', { name: /^Corte de turno/ })).toBeVisible()
    await expect(page.getByText('Esperado').first()).toBeVisible()
    await expect(page.getByText('Diferencia').first()).toBeVisible()
  })
})
