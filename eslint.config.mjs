import js from '@eslint/js';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default tseslint.config(
  {
    ignores: [
      '**/dist/**',
      '**/node_modules/**',
      '.venv/**',
      'apps/api/**',
      'packages/sdk-js/src/openapi.d.ts',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    files: ['**/*.ts'],
    rules: {
      'no-restricted-properties': [
        'error',
        {
          property: 'innerHTML',
          message: 'Usa DOM seguro (textContent/createElement): el widget nunca inyecta HTML.',
        },
        { property: 'outerHTML', message: 'Usa DOM seguro.' },
      ],
      'no-eval': 'error',
      'no-implied-eval': 'error',
      '@typescript-eslint/no-explicit-any': 'error',
    },
  },
  { files: ['**/*.mjs', '**/*.config.ts', '**/e2e/serve.mjs'], languageOptions: { globals: globals.node } },
  { files: ['demo/**/*.js'], languageOptions: { sourceType: 'script', globals: globals.browser } },
  {
    files: ['integraciones/drupal/tests/js/**/*.mjs'],
    languageOptions: { globals: { ...globals.browser, ...globals.node } },
  },
  {
    // Behaviors del conector Drupal (script clásico con las globales de Drupal).
    files: ['integraciones/drupal/**/js/**/*.js'],
    languageOptions: {
      sourceType: 'script',
      globals: { ...globals.browser, Drupal: 'readonly', drupalSettings: 'readonly', once: 'readonly' },
    },
  },
  {
    files: ['**/tests/**/*.ts', '**/e2e/**/*.ts'],
    rules: { 'no-restricted-properties': 'off', '@typescript-eslint/no-non-null-assertion': 'off' },
  },
);
