import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['resources/js/**/*.spec.ts', 'scripts/**/*.spec.mjs'],
    environment: 'jsdom',
    passWithNoTests: true,
    coverage: {
      provider: 'v8',
      include: ['resources/js/**/*.{ts,vue}'],
      reporter: ['text', 'lcov'],
    },
  },
});
