# cherryPOS

Punto de venta para Nicaragua. Núcleo único con módulos verticales: retail y
restaurante en la primera fase, gimnasio en la segunda, ferretería y farmacia en
la tercera.

Se instala en un **servidor dentro de la red del negocio**; las terminales
entran por navegador. Internet es opcional y solo para respaldos y para
sincronizar con el ERP hermano, `cherryB`.

Producto **cerrado y propietario**. No reutiliza código de terceros con
obligaciones de atribución.

---

## Estructura

```
apps/api/        Laravel 12 + PostgreSQL     (convenciones de cherryB)
apps/pos/        Vue 3 + Nuxt UI 4 + PWA     (convenciones de cherryF)
packages/calc/   Motor de cálculo TypeScript + fixtures compartidos
docs/            Contrato con el ERP, arquitectura, operación (fuera del repositorio)
deploy/          Compose y guiones de respaldo, simulacro, restauración y actualización
```

## Puesta en marcha

```bash
# Base de datos
docker compose -f deploy/docker-compose.dev.yml up -d

# API
cd apps/api
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve

# Frontend
pnpm install
pnpm dev
```

El seeder de desarrollo deja una sucursal, una terminal (`CAJA-01`, secreto
`terminal-dev`) y un empleado `ADMIN` con PIN `1234` y PIN de supervisor `9876`.
Nada de eso sirve en producción: allí los emite el instalador.

## Comprobaciones

```bash
pnpm check     # lint + typecheck + Vitest + PHPUnit
```

El motor de cálculo existe dos veces —PHP y TypeScript— y **el CI falla si
difieren en un centavo**. Ningún cambio de fórmula entra sin su caso en
`packages/calc/fixtures/`, y el caso se escribe antes que la implementación.

## Documentación

**Vive en `docs/`, que no se versiona** — igual que `documents/`. Se entrega por
otro canal junto con el código, y sin ella faltan tres cosas que el repositorio
no puede reponer solo:

- `docs/CONTRATO-CHERRYB.md` — integración con el ERP. Ante divergencia con el
  código, **gana `cherryB`**. Es la referencia de la cola de replicación y de
  `tax_difference`.
- `docs/ARQUITECTURA.md` — decisiones de estructura y precondiciones del esquema.
- `docs/OPERACION.md` — instalación, respaldo y recuperación. Es lo que se lee
  cuando hay que restaurar un servidor, y los guiones de `deploy/scripts/` lo
  citan por sección.
