import eslintPluginVue from 'eslint-plugin-vue'
import ts from 'typescript-eslint'

/** Mismo conjunto de reglas que `cherryF`: dos proyectos, un solo estilo. */
export default ts.config(
  { ignores: ['dist', 'dev-dist', 'node_modules', 'src/route-map.d.ts', 'components.d.ts', 'auto-imports.d.ts'] },
  ...ts.configs.recommended,
  ...eslintPluginVue.configs['flat/recommended'],
  {
    files: ['*.vue', '**/*.vue'],
    languageOptions: {
      parserOptions: {
        parser: '@typescript-eslint/parser'
      }
    },
    rules: {
      'vue/multi-word-component-names': 'off',
      'vue/max-attributes-per-line': ['error', { singleline: 3 }],
      'no-undef': 'off'
    }
  }
)
