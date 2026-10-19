import { defineConfig } from 'cypress';

export default defineConfig({
  // Node resolves names itself, and not every system maps *.localhost; browsers always do.
  hosts: { 'admin.localhost': '127.0.0.1' },
  e2e: {
    baseUrl: process.env.CYPRESS_BASE_URL ?? 'http://localhost',
    specPattern: 'cypress/e2e/**/*.cy.ts',
    supportFile: 'cypress/support/e2e.ts',
    video: true,
    screenshotOnRunFailure: true,
    viewportWidth: 1280,
    viewportHeight: 800,
    defaultCommandTimeout: 8000,
    // Every test starts signed out; specs sign in through the testing routes or the form.
    testIsolation: true,
    retries: { runMode: 1, openMode: 0 },
    // Public values only; none of these is a secret. The testing token matches TESTING_E2E_TOKEN in .env.e2e.
    expose: {
      TESTING_E2E_TOKEN: 'e2e-local-token',
      DEMO_PASSWORD: 'password',
      ADMIN_ORIGIN: 'http://admin.localhost',
    },
  },
});
