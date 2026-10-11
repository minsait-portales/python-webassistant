// Pruebas del behavior JavaScript del conector Drupal (jsdom, sin Drupal real).
import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    root: import.meta.dirname,
    environment: 'jsdom',
    include: ['**/*.test.mjs'],
  },
});
