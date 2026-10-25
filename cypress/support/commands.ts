/// <reference types="cypress" />

import { selectors } from './selectors';

type ColorScheme = 'light' | 'dark' | 'none';

declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Cypress {
    interface Chainable {
      /** Rebuilds the database from the demo seed and releases the frozen clock. */
      resetDemo(): Chainable<void>;
      /** Signs a seeded person in through the testing route, without the form. */
      signInAs(email: string): Chainable<void>;
      /** Signs in through the real sign-in form; pass `twoFactor` for people who have two-factor on (Maya and Jon). */
      signInThroughForm(
        email: string,
        password?: string,
        options?: { twoFactor?: boolean },
      ): Chainable<void>;
      /** Empties the mailbox. */
      clearMailbox(): Chainable<void>;
      /** Yields the newest email to an address once one has arrived: its subject, its text and the first link in it. */
      latestEmail(
        to: string,
        subjectContains?: string,
      ): Chainable<{ subject: string; text: string; link: string | null }>;
      /** Yields the code an authenticator app would show now, never one already spent in this spec. */
      totp(secret?: string): Chainable<string>;
      /** Types a six-digit code into the code input by pasting it, as a person with a password manager would. */
      fillOtp(code: string): Chainable<void>;
      /** Freezes the server clock at an ISO instant, or releases it with null. */
      freezeServerClock(now: string | null): Chainable<void>;
      /** Emulates the operating system color scheme (Chrome only). `none` removes the emulation. */
      emulateColorScheme(scheme: ColorScheme): Chainable<void>;
      /** Stops the application scripts from loading, so only what the server rendered before them is left. */
      blockAppScripts(): Chainable<void>;
    }
  }
}

const testingHeaders = () => ({ 'X-Testing-Token': String(Cypress.expose('TESTING_E2E_TOKEN')) });

Cypress.Commands.add('resetDemo', () => {
  cy.request({
    method: 'POST',
    url: '/_testing/reset',
    headers: testingHeaders(),
    timeout: 120000,
  });
});

Cypress.Commands.add('signInAs', (email: string) => {
  cy.request({
    method: 'POST',
    url: '/_testing/login',
    headers: testingHeaders(),
    body: { email },
  });
});

Cypress.Commands.add(
  'signInThroughForm',
  (email: string, password?: string, options?: { twoFactor?: boolean }) => {
    cy.visit('/signin');
    cy.get(selectors.login.email).type(email);
    cy.get(selectors.login.password).type(password ?? String(Cypress.expose('DEMO_PASSWORD')), {
      log: false,
    });
    cy.get(selectors.login.submit).click();

    if (options?.twoFactor) {
      cy.location('pathname').should('eq', '/two-factor-challenge');
      cy.totp().then((code) => cy.fillOtp(code));
    }
  },
);

Cypress.Commands.add('clearMailbox', () => {
  cy.request({ method: 'DELETE', url: '/_testing/mailpit', headers: testingHeaders() });
});

type Mail = { to: string[]; subject: string; text: string; created: string };

Cypress.Commands.add('latestEmail', (to: string, subjectContains?: string) => {
  const attempt = (
    remaining: number,
  ): Cypress.Chainable<{ subject: string; text: string; link: string | null }> =>
    cy
      .request({ url: `/_testing/mailpit?to=${encodeURIComponent(to)}`, headers: testingHeaders() })
      .then((response) => {
        const messages = (response.body.data as Mail[]).filter(
          (mail) => !subjectContains || mail.subject.includes(subjectContains),
        );
        const newest = messages.sort((a, b) => b.created.localeCompare(a.created))[0];

        if (newest) {
          const link = newest.text.match(/https?:\/\/[^\s]+/)?.[0] ?? null;
          return cy.wrap({ subject: newest.subject, text: newest.text, link });
        }

        if (remaining === 0) throw new Error(`No email to ${to} arrived.`);
        return cy.wait(1000, { log: false }).then(() => attempt(remaining - 1));
      });

  return attempt(20);
});

// Steps already used in this spec, so a second sign-in in the same half minute takes the next step the server accepts.
let lastTotpStep = 0;

Cypress.Commands.add('totp', (secret?: string) => {
  return cy.request({ url: '/_testing/clock', headers: testingHeaders() }).then((response) => {
    const now = Math.floor(new Date(response.body.data.now as string).getTime() / 1000 / 30);
    const step = Math.min(Math.max(now, lastTotpStep + 1), now + 1);
    lastTotpStep = step;

    return cy.task<string>('totp', {
      secret: secret ?? String(Cypress.expose('DEMO_TOTP_SECRET')),
      step,
    });
  });
});

Cypress.Commands.add('fillOtp', (code: string) => {
  cy.get('[data-test="otp-input"]').trigger('paste', { clipboardData: { getData: () => code } });
});

Cypress.Commands.add('freezeServerClock', (now: string | null) => {
  cy.request({ method: 'POST', url: '/_testing/clock', headers: testingHeaders(), body: { now } });
});

Cypress.Commands.add('emulateColorScheme', (scheme: ColorScheme) => {
  Cypress.automation('remote:debugger:protocol', {
    command: 'Emulation.setEmulatedMedia',
    params: {
      features: [{ name: 'prefers-color-scheme', value: scheme === 'none' ? '' : scheme }],
    },
  });
});

Cypress.Commands.add('blockAppScripts', () => {
  cy.intercept(
    { method: 'GET', url: /\/build\/assets\/.*\.js(\?.*)?$/ },
    { statusCode: 200, headers: { 'content-type': 'text/javascript' }, body: '' },
  );
});

export {};
