import { expect, test } from '@playwright/test'

/**
 * La credencial de la terminal que dejó de servir.
 *
 * No es un caso raro: el token dura veinticuatro horas, así que **la primera
 * pantalla de cada mañana lo encuentra vencido**, y activar la misma terminal en
 * otro equipo invalida el anterior a propósito —para que un equipo robado deje
 * de servir en cuanto el local vuelve a abrir—.
 *
 * Lo que la caja mostraba era el 401 de fábrica, `Unauthenticated.`: en inglés,
 * sin decir qué hacer y sin camino de vuelta. Y como el error aparecía al
 * intentar entrar con el PIN, la lectura natural era que el PIN estaba mal.
 */

test('token de terminal revocado: la caja manda a activar, no a un error', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('cherrypos.terminal.token', '1|token-revocado-por-otra-activacion')
    localStorage.setItem('cherrypos.operator.session', 'sesion-vieja')
  })

  await page.goto('/')
  await page.waitForTimeout(3000)

  console.log('URL:', page.url())
  console.log((await page.locator('body').innerText()).replace(/\n+/g, ' | ').slice(0, 200))

  await expect(page).toHaveURL(/\/terminal$/)

  // Y desde ahí se entra normalmente.
  await page.getByLabel('Código de sucursal').fill('001')
  await page.getByLabel('Código de terminal').fill('CAJA-01')
  await page.getByLabel('Secreto de la terminal').fill('terminal-dev')
  await page.getByRole('button', { name: 'Activar terminal' }).click()
  await page.waitForTimeout(2500)

  if (page.url().endsWith('/login')) {
    await page.getByLabel('Código de cajero').fill('ADMIN')
    await page.getByLabel('PIN', { exact: true }).fill('1234')
    await page.getByRole('button', { name: 'Entrar' }).click()
    await page.waitForTimeout(2000)
  }

  console.log('final:', page.url())
  await expect(page.getByText('Administrador')).toBeVisible()
})
