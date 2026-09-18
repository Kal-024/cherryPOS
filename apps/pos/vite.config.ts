import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueRouter from 'vue-router/vite'
import vueLayouts from 'vite-plugin-vue-layouts'
import ui from '@nuxt/ui/vite'
import { VitePWA } from 'vite-plugin-pwa'

/**
 * Misma configuración que `cherryF`, con una divergencia deliberada: el POS es
 * **PWA**. La caja tiene que abrir aunque el servidor no conteste, y el service
 * worker es además requisito del modo degradado de H6.
 */
export default defineConfig({
  define: {
    // vue-i18n solo con Composition API: quita la instalación heredada y los
    // ganchos de devtools en producción.
    __VUE_I18N_FULL_INSTALL__: false,
    __VUE_I18N_LEGACY_API__: false,
    __INTLIFY_PROD_DEVTOOLS__: false
  },
  plugins: [
    vueRouter({ dts: 'src/route-map.d.ts' }),
    vueLayouts(),
    vue(),
    ui({
      icon: {
        // Los iconos se empaquetan en el build desde la colección local en vez
        // de pedirlos a api.iconify.design: la caja trabaja sin internet.
        clientBundle: { scan: true, sizeLimitKb: 1024 }
      },
      ui: {
        colors: { primary: 'red', neutral: 'zinc' }
      }
    }),
    VitePWA({
      registerType: 'prompt',
      // Nunca una actualización silenciosa en medio de un turno: el cajero
      // decide cuándo recargar.
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,woff2}'],
        navigateFallbackDenylist: [/^\/api\//]
      },
      manifest: {
        name: 'cherryPOS',
        short_name: 'cherryPOS',
        description: 'Punto de venta cherryPOS',
        lang: 'es',
        start_url: '/',
        display: 'standalone',
        background_color: '#18181b',
        theme_color: '#18181b',
        // Provisional: un SVG escalable mientras no exista identidad visual.
        // El logo definitivo sale de la licencia firmada y no es editable
        // desde la aplicación (Q-08).
        icons: [
          { src: '/favicon.svg', sizes: 'any', type: 'image/svg+xml' },
          { src: '/favicon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'maskable' }
        ]
      },
      devOptions: { enabled: false }
    })
  ],
  server: {
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY ?? 'http://127.0.0.1:8000',
        changeOrigin: true
      }
    }
  }
})
