import { computed } from 'vue'
import { createI18n } from 'vue-i18n'
import en from './locales/en'
import es from './locales/es'

/**
 * P6: ningún literal visible en el código, tampoco en los mensajes de error.
 *
 * Español por defecto e inglés como segundo idioma. **El ruso quedó descartado**
 * (P-14): cada idioma agrega costo a cada texto nuevo durante toda la vida del
 * producto, y con Nicaragua como mercado inicial no se deducía del contexto.
 */
export type AppLocale = 'es' | 'en'

/** Las etiquetas quedan en su propio idioma a propósito: nunca se traducen. */
export const SUPPORTED_LOCALES = [
  { value: 'es', label: 'Español' },
  { value: 'en', label: 'English' }
] as const satisfies ReadonlyArray<{ value: AppLocale, label: string }>

export const DEFAULT_LOCALE: AppLocale = 'es'

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: DEFAULT_LOCALE,
  fallbackLocale: DEFAULT_LOCALE,
  missingWarn: import.meta.env.DEV,
  fallbackWarn: false,
  messages: { es, en }
})

/**
 * Para código a nivel de módulo (utilidades, composables) que no puede llamar a
 * `useI18n()`. Invocarlo **dentro** del cuerpo de la función, nunca como valor
 * por defecto de un parámetro: eso congelaría el idioma al importar.
 */
export const t = i18n.global.t

export const i18nLocale = i18n.global.locale

const FORMAT_LOCALE: Record<AppLocale, string> = { es: 'es-NI', en: 'en-US' }

export const formatLocale = computed(() => FORMAT_LOCALE[i18n.global.locale.value as AppLocale])
