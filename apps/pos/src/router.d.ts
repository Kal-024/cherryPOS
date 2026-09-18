import 'vue-router'

/**
 * Metadatos de ruta propios del POS.
 *
 * `permission` es el código que la pantalla exige —el mismo vocabulario que el
 * middleware `permission:` de la API— y lo lee el guardia de `main.ts`. El
 * `import` de arriba no es decorativo: sin él este archivo sería un script
 * global y `declare module` **sustituiría** a vue-router en vez de ampliarlo.
 */
declare module 'vue-router' {
  interface RouteMeta {
    permission?: string
  }
}
