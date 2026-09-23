import { expect, test, type Page } from '@playwright/test'
import { activateTerminal, addByName } from './support/terminal'

/**
 * El salón, de punta a punta (F1-B).
 *
 * Recorre lo que hace un mesero en un servicio: abrir la mesa, pedir un plato
 * con su término, mandarlo a cocina y verlo aparecer en el KDS. Es el equivalente
 * del guion de F1-A para el perfil restaurante, y por la misma razón: el foco que
 * no vuelve, el botón que no aparece y el modal que tapa la comanda no se ven en
 * ninguna prueba unitaria.
 *
 * Precondición, igual que la compuerta de F1-A:
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DemoDataSeeder
 */

const TERMINAL = { branch: '001', terminal: 'CAJA-01', secret: 'terminal-dev' }
/** La terminal del salón: misma sucursal, perfil `restaurant` (A-04). */
const DINING_TERMINAL = 'SALON-01'
const OPERATOR = { code: 'ADMIN', pin: '1234' }

async function signIn(page: Page, terminal = TERMINAL.terminal) {
  await page.goto('/')

  // Cada prueba arranca con un navegador limpio: la terminal se activa de nuevo
  // y el guardia de ruta manda al PIN (D-05).
  await expect(page).toHaveURL(/\/terminal$/)
  await activateTerminal(page, { ...TERMINAL, terminal })

  // La sesión del cajero es de la terminal y vive en el servidor: si sigue
  // abierta, la caja entra directamente (D-05).
  if (page.url().endsWith('/login')) {
    await page.getByLabel('Código de cajero').fill(OPERATOR.code)
    await page.getByLabel('PIN', { exact: true }).fill(OPERATOR.pin)
    await page.getByRole('button', { name: 'Entrar' }).click()
  }

  // Sin turno abierto no se vende, tampoco en el salón (H4.1). La caja puede
  // aparecer lista —si otra prueba ya abrió el turno— o pidiendo el fondo.
  const search = page.getByPlaceholder('Buscar producto o escanear código')
  const openShift = page.getByRole('button', { name: 'Abrir turno' })

  await expect(search.or(openShift).first()).toBeVisible()

  // El panel de turno se redibuja al terminar de leer el estado del servidor, y
  // el botón puede desprenderse del DOM en medio del clic. Se reintenta en vez
  // de dar por fallado el guion por una carrera de la pantalla.
  for (let attempt = 0; attempt < 3 && await openShift.isVisible().catch(() => false); attempt++) {
    await page.getByLabel('Fondo inicial').fill('1000')
    await openShift.click({ timeout: 5_000 }).catch(() => undefined)
    await page.waitForTimeout(500)
  }

  await expect(search).toBeVisible()
}

test.describe('Salón y cocina', () => {
  test.describe.configure({ mode: 'serial' })

  /**
   * Una sola activación de terminal para todo el guion.
   *
   * No es ahorro de tiempo: **es lo que hace una terminal de verdad**. La
   * credencial se pone una vez al día y después cada cajero entra con su PIN
   * (D-05), así que reactivarla en cada prueba chocaba con la protección de
   * fuerza bruta del servidor —diez intentos por minuto (B-15)— y el guion
   * fallaba con "Too Many Attempts" en vez de con lo que estuviera probando.
   */
  let page: Page

  test.beforeAll(async ({ browser }) => {
    page = await browser.newPage()
    await signIn(page)
  })

  test.afterAll(async () => {
    await page.context().close()
  })

  test('1 · el mapa muestra las mesas del local', async () => {
    await page.goto('/salon')

    // Las mesas van donde están de verdad: el plano es el del local, no una
    // lista de botones.
    await expect(page.getByRole('button', { name: /Mesa 1/ })).toBeVisible()
    await expect(page.getByRole('button', { name: /Mesa 4/ })).toBeVisible()
    await expect(page.getByText('0 de 4 ocupadas')).toBeVisible()
  })

  test('2 · atender una mesa: abrir, pedir con término y mandar a cocina', async () => {
    await page.goto('/salon')

    await page.getByRole('button', { name: /Mesa 1/ }).click()
    await page.getByRole('button', { name: 'Abrir cuenta' }).click()

    // La cuenta es la venta: se sigue en la misma pantalla de caja (D-01).
    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByRole('button', { name: /Mandar a cocina/ })).toBeVisible()

    await addByName(page, 'Lomo')

    // Descubrir el obligatorio después mandaría al mesero de vuelta a la mesa.
    await expect(page.getByText('Falta responder: Término')).toBeVisible()

    await page.getByRole('button', { name: 'Término medio' }).click()
    await page.getByRole('button', { name: 'Doble queso' }).click()
    await page.getByRole('button', { name: 'Agregar al pedido' }).click()

    // 350 del plato más 45 del agregado, con el impuesto adentro.
    await expect(page.getByText('395.00').first()).toBeVisible()

    await page.getByRole('button', { name: /Mandar a cocina/ }).click()
    await expect(page.getByText('Comanda enviada', { exact: true })).toBeVisible()
  })

  test('3 · la mesa queda ocupada en el mapa', async () => {
    await page.goto('/salon')

    // El estado se deduce de la venta suspendida: nadie marcó la mesa.
    await expect(page.getByText('1 de 4 ocupadas')).toBeVisible()
    await expect(page.getByRole('button', { name: /Mesa 1/ })).toContainText('395.00')
  })

  test('3b · volver a la mesa ya atendida la vuelve a abrir', async () => {
    await page.goto('/salon')

    // **Acá estaba el fallo.** La cuenta quedó en `draft` al entrar a la caja y
    // nada la re-suspende al salir, pero el mapa la sigue dando por ocupada. Con
    // `resume()` aceptando solo suspendidas, este segundo toque respondía "esta
    // venta no está suspendida" y dejaba la mesa ocupada e intocable: el mesero
    // veía el plato en cocina y no podía agregarle la bebida.
    await page.getByRole('button', { name: /Mesa 1/ }).click()

    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByText('395.00').first()).toBeVisible()
    await expect(page.getByText('no está suspendida')).toHaveCount(0)
  })

  test('3c · unir dos mesas arrastrando una sobre otra', async () => {
    await page.goto('/salon')

    // El camino anterior elegía la mesa principal **por su cuenta** —la primera
    // libre del área— y solo dejaba marcar cuáles se le sumaban: nadie entendía
    // cuál mandaba. Juntar mesas es un gesto físico, y acá es el mismo gesto.
    const origen = page.locator('[data-table-id]').filter({ hasText: 'Mesa 3' })
    const destino = page.locator('[data-table-id]').filter({ hasText: 'Mesa 4' })

    const desde = await origen.boundingBox()
    const hasta = await destino.boundingBox()

    await page.mouse.move((desde?.x ?? 0) + 40, (desde?.y ?? 0) + 40)
    await page.mouse.down()
    await page.mouse.move((hasta?.x ?? 0) + 30, (hasta?.y ?? 0) + 30, { steps: 8 })
    await page.mouse.move((hasta?.x ?? 0) + 50, (hasta?.y ?? 0) + 50, { steps: 4 })
    await page.mouse.up()

    // El aviso aparece dos veces en el árbol: el título visible y el anuncio
    // que lee el lector de pantalla.
    await expect(page.getByText('Mesas unidas').first()).toBeVisible()

    // Unidas se dibujan pegadas: dos mesas que comparten cuenta y siguen cada
    // una en su rincón no se leen como una sola unidad.
    await expect(page.getByRole('button', { name: /Separar/ })).toBeVisible()
  })

  test('4 · los cursos: el postre se pide ya y sale después', async ({ browser }) => {
    // La terminal del salón es la que sirve por tiempos: en el mostrador la
    // columna de curso no existe porque una pulpería no sirve por cursos. Es la
    // única prueba que activa una segunda terminal, y por eso trae la suya.
    const page = await browser.newPage()
    await signIn(page, DINING_TERMINAL)
    await page.goto('/salon')

    // Mesa propia: la 1 la está atendiendo la caja del mostrador, y dos
    // terminales sobre la misma cuenta es otro guion.
    await page.getByRole('button', { name: /Mesa 2/ }).click()
    await page.getByRole('button', { name: 'Abrir cuenta' }).click()
    await expect(page).toHaveURL(/\/$/)

    // La cerveza sale ya —la prepara la barra— y el postre espera.
    await addByName(page, 'Cerveza')
    await addByName(page, 'Tres leches')

    // El postre se pide con el resto y se marca para después: volver a la mesa a
    // pedirlo sería perder los veinte minutos que tarda.
    await page.getByRole('combobox', { name: 'Curso' }).last().click()
    await page.getByRole('option', { name: 'Postre' }).click()
    await page.getByRole('button', { name: /Mandar a cocina/ }).click()
    await expect(page.getByText('Comanda enviada', { exact: true })).toBeVisible()

    // Lo retenido se ve en el salón, no en el pase: es el mesero quien decide
    // cuándo soltarlo.
    await page.goto('/salon')
    const fire = page.getByRole('button', { name: /Mesa 2 · Marchar/ })
    await expect(fire).toBeVisible()

    await fire.click()
    await expect(page.getByText('Curso marchado', { exact: true })).toBeVisible()
    await expect(fire).toBeHidden()

    await page.context().close()
  })

  test('5 · el relevo: sale un cajero y entra otro sin tocar la terminal', async () => {
    await page.goto('/')
    await expect(page.getByText('Administrador')).toBeVisible()

    // Cerrar la sesión tiene que **llevar** al PIN. Sin eso la caja quedaba
    // dibujada con el cajero ya cerrado: el siguiente no tenía dónde
    // identificarse y la única salida visible era recargar.
    await page.getByRole('button', { name: 'Salir' }).click()
    await expect(page).toHaveURL(/\/login$/)

    // Y la terminal sigue autenticada: eso es todo el punto de D-05. Pedir el
    // secreto del equipo en cada relevo es lo que la doble credencial evita.
    await page.getByLabel('Código de cajero').fill(OPERATOR.code)
    await page.getByLabel('PIN', { exact: true }).fill(OPERATOR.pin)
    await page.getByRole('button', { name: 'Entrar' }).click()

    await expect(page).toHaveURL(/\/$/)
    await expect(page.getByText('Administrador')).toBeVisible()
  })

  test('6 · el KDS muestra la comanda y la avanza', async () => {
    await page.goto('/cocina')

    await expect(page.getByText('Lomo a la plancha')).toBeVisible()
    // Los modificadores van en la comanda: cocina no consulta el catálogo.
    await expect(page.getByText('Término medio · Doble queso')).toBeVisible()

    // Hay más de una comanda en el pase —el lomo y el postre marchado—, así que
    // se avanza la primera: es la que lleva más tiempo esperando, que es como se
    // despacha una cocina.
    await page.getByRole('button', { name: 'Empezar' }).first().click()
    await expect(page.getByRole('button', { name: 'Lista' }).first()).toBeVisible()
  })
})
