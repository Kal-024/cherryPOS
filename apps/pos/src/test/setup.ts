import { config } from '@vue/test-utils'
import { vi } from 'vitest'
import { i18n } from '../i18n'

/**
 * Montaje de componentes sin Nuxt UI.
 *
 * Los `U*` se reemplazan por equivalentes mínimos porque lo que estas pruebas
 * verifican es **el comportamiento de la pantalla de caja**, no el de la
 * biblioteca: que Enter sobre un código leído agregue una línea, que el foco
 * vuelva a la búsqueda, que un importe en cero no ocupe espacio.
 *
 * Montar la biblioteca real haría las pruebas más lentas y las ataría a su
 * marcado interno, que cambia entre versiones y no es nuestro.
 */

const passthrough = (tag: string) => ({
  inheritAttrs: false,
  template: `<${tag} v-bind="$attrs"><slot /></${tag}>`
})

/**
 * `useToast` lo auto-importa el plugin de Nuxt UI en la aplicación, y acá no hay
 * plugin. Sin este doble, cualquier componente que avise algo revienta al
 * montarse — y avisar es justo lo que hace media caja.
 */
vi.stubGlobal('useToast', () => ({ add: vi.fn(), remove: vi.fn(), clear: vi.fn() }))

config.global.plugins = [i18n]

config.global.stubs = {
  UInput: {
    inheritAttrs: false,
    props: ['modelValue'],
    emits: ['update:modelValue'],
    // Sin conversiones de TypeScript: estas plantillas se compilan en tiempo
    // de ejecución y el compilador de plantillas no entiende `as`.
    template: '<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />'
  },
  // `label` se dibuja como texto: media interfaz de la trastienda son botones
  // sin contenido en la ranura, y un stub que ignore la propiedad haría que
  // las pruebas no vieran ninguno.
  UButton: {
    inheritAttrs: false,
    props: ['label'],
    template: '<button v-bind="$attrs">{{ label }}<slot /></button>'
  },
  UBadge: passthrough('span'),
  UIcon: { template: '<i />' },
  UKbd: { props: ['value'], template: '<kbd>{{ value }}</kbd>' },
  UAlert: {
    props: ['description', 'title'],
    template: '<div role="alert">{{ title }} {{ description }}</div>'
  },
  USelect: {
    props: ['modelValue', 'items'],
    emits: ['update:modelValue'],
    // Las opciones se dibujan de verdad: lo que varias pantallas necesitan
    // comprobar es *qué* se puede elegir —que la unidad abra sin
    // preseleccionar, que exista "sin categoría"—, y eso no se ve en un
    // `<select>` vacío.
    template: '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="(item, index) in items" :key="index" :value="item.value ?? item">{{ item.label ?? item }}</option></select>'
  },
  UFormField: {
    props: ['label', 'help', 'hint'],
    template: '<label>{{ label }}<slot />{{ help }}{{ hint }}</label>'
  },
  UTextarea: {
    inheritAttrs: false,
    props: ['modelValue'],
    emits: ['update:modelValue'],
    template: '<textarea v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />'
  },
  USwitch: {
    props: ['modelValue', 'label', 'description'],
    emits: ['update:modelValue'],
    template: '<label>{{ label }}{{ description }}<input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /></label>'
  },
  UCard: { template: '<div><slot /></div>' },
  // Las pestañas se dibujan como botones: lo que las pruebas necesitan es poder
  // cambiar de una a otra, no el marcado de la biblioteca.
  UTabs: {
    props: ['modelValue', 'items'],
    emits: ['update:modelValue'],
    template: '<div><button v-for="(item, index) in items" :key="index" type="button" @click="$emit(\'update:modelValue\', item.value ?? item)">{{ item.label ?? item }}</button></div>'
  },
  UModal: { template: '<div><slot name="body" /><slot name="footer" /></div>' }
}
