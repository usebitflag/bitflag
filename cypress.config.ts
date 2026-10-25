import { createHmac } from 'node:crypto';
import { defineConfig } from 'cypress';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(encoded: string): Buffer {
  let bits = '';
  for (const character of encoded.replace(/=+$/, '').toUpperCase()) {
    bits += ALPHABET.indexOf(character).toString(2).padStart(5, '0');
  }
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

/** The six-digit code an authenticator app shows for a time step (RFC 6238, SHA-1, 30-second steps). */
function totpCode(secret: string, step: number): string {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hash = createHmac('sha1', base32Decode(secret)).update(counter).digest();
  const offset = (hash[19] ?? 0) & 0x0f;
  const value =
    (((hash[offset] ?? 0) & 0x7f) << 24) |
    (((hash[offset + 1] ?? 0) & 0xff) << 16) |
    (((hash[offset + 2] ?? 0) & 0xff) << 8) |
    ((hash[offset + 3] ?? 0) & 0xff);
  return String(value % 1_000_000).padStart(6, '0');
}

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
    setupNodeEvents(on) {
      on('task', {
        /** The code for a time step, so a spec can enter what an authenticator app would show. */
        totp({ secret, step }: { secret: string; step: number }): string {
          return totpCode(secret, step);
        },
      });
    },
    // Public values only; none of these is a secret. The testing token matches TESTING_E2E_TOKEN in .env.e2e.
    expose: {
      TESTING_E2E_TOKEN: 'e2e-local-token',
      DEMO_PASSWORD: 'password',
      DEMO_TOTP_SECRET: 'JBSWY3DPEHPK3PXP',
      DEMO_INVITATION_TOKEN: 'demo-invitation-token-for-the-e2e-specs_012',
      ADMIN_ORIGIN: 'http://admin.localhost',
    },
  },
});
