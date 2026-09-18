import { defineConfig, devices } from '@playwright/test'

/**
 * El guion de la compuerta de F1-A, automatizado (§8 del plan).
 *
 * **No se declara F1-A cerrado hasta que esto pase completo.** Lo que verifica
 * no son los cálculos —de eso se encargan los fixtures compartidos, que PHP y
 * TypeScript corren por igual— sino el cableado de punta a punta: que el cajero
 * pueda hacer las diez cosas del guion desde un navegador, con la API real
 * detrás.
 *
 * Dos procesos, como en desarrollo: `php artisan serve` y Vite, que proxea
 * `/api` hacia la API. Playwright los levanta y los apaga.
 *
 * **Precondición: la base tiene que estar sembrada.**
 *
 *   php artisan migrate:fresh --seed
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * No se hace desde aquí a propósito: un `migrate:fresh` disparado por una
 * herramienta de pruebas es la forma más rápida de borrar la base de desarrollo
 * de alguien que solo quería correr un test.
 */
export default defineConfig({
  testDir: './e2e',
  // El guion es una secuencia: el turno se abre una vez y las ventas siguientes
  // dependen de él. Paralelizarlo sería contar el mismo cajón dos veces.
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: process.env.CI ? [['github'], ['list']] : [['list']],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'es-NI'
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } }
  ],
  webServer: process.env.E2E_BASE_URL
    ? undefined
    : [
        {
          command: 'php artisan serve --host=127.0.0.1 --port=8000',
          cwd: 'apps/api',
          url: 'http://127.0.0.1:8000/api/system/version',
          reuseExistingServer: !process.env.CI,
          timeout: 60_000
        },
        {
          command: 'pnpm --filter @cherrypos/pos dev --host 127.0.0.1 --port 5173',
          url: 'http://127.0.0.1:5173',
          reuseExistingServer: !process.env.CI,
          timeout: 120_000
        }
      ]
})
