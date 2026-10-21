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
      /** Signs in through the real sign-in form. */
      signInThroughForm(email: string, password?: string): Chainable<void>;
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

Cypress.Commands.add('signInThroughForm', (email: string, password?: string) => {
  cy.visit('/signin');
  cy.get(selectors.login.email).type(email);
  cy.get(selectors.login.password).type(password ?? String(Cypress.expose('DEMO_PASSWORD')), {
    log: false,
  });
  cy.get(selectors.login.submit).click();
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
