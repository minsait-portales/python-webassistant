import path from 'node:path';

import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: {
    alias: { '@webassistant/sdk': path.resolve(import.meta.dirname, '../../packages/sdk-js/src/index.ts') },
  },
  define: {
    __WIDGET_VERSION__: JSON.stringify('test'),
    __WIDGET_LOGO__: JSON.stringify('assets/logo.test.png'),
    __WIDGET_ENTRY__: JSON.stringify('assets/widget.test.js'),
    __WIDGET_INTEGRITY__: JSON.stringify(''),
  },
  test: {
    environment: 'jsdom',
    include: ['tests/**/*.test.ts'],
    restoreMocks: true,
  },
});
