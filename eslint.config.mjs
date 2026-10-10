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
    files: ['**/tests/**/*.ts', '**/e2e/**/*.ts'],
    rules: { 'no-restricted-properties': 'off', '@typescript-eslint/no-non-null-assertion': 'off' },
  },
);
