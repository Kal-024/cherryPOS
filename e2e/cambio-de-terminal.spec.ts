import { expect, test } from '@playwright/test'

/**
 * Cambiar de terminal en el mismo equipo (D-05).
 *
 * La activación es de la terminal, no del navegador. Sin una forma de
 * deshacerla, un equipo activado como mostrador **no podía entrar como salón**
 * salvo borrando a mano los datos del navegador, y eso no es algo que se le pida
 * a nadie en un local: es el equipo de repuesto que entra como la caja que se
 * rompió, o la tablet que de día atiende mesas y de noche el mostrador.
 */
test('un equipo activado como mostrador puede pasar a ser el salón', async ({ page }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/terminal$/)

  await page.getByLabel('Código de sucursal').fill('001')
  await page.getByLabel('Código de terminal').fill('CAJA-01')
  await page.getByLabel('Secreto de la terminal').fill('terminal-dev')
  await page.getByRole('button', { name: 'Activar terminal' }).click()
  await expect(page).not.toHaveURL(/\/terminal$/)

  // La pantalla del relevo es donde se descubre estar en la caja equivocada.
  if (!page.url().endsWith('/login')) {
    await page.getByRole('button', { name: 'Salir' }).click()
  }

  await expect(page).toHaveURL(/\/login$/)
  await expect(page.getByText('CAJA-01')).toBeVisible()

  await page.getByRole('button', { name: 'Cambiar de terminal' }).click()
  await page.getByRole('button', { name: 'Desactivar y cambiar' }).click()

  await expect(page).toHaveURL(/\/terminal$/)

  await page.getByLabel('Código de sucursal').fill('001')
  await page.getByLabel('Código de terminal').fill('SALON-01')
  await page.getByLabel('Secreto de la terminal').fill('terminal-dev')
  await page.getByRole('button', { name: 'Activar terminal' }).click()

  await expect(page).toHaveURL(/\/login$/)
  // Ahora es el salón, y con él su vocabulario y su perfil de pantalla (A-04).
  await expect(page.getByText('SALON-01')).toBeVisible()
})
