import { selectors } from '../support/selectors';

const { login } = selectors;

describe('two-factor sign-in', () => {
  before(() => cy.resetDemo());

  it('turns on two-factor, shows the recovery codes once, and asks for a code at the next sign-in', () => {
    cy.signInAs('priya@acme.dev');
    cy.visit('/account/security');

    cy.get('[data-test="two-factor-enable"]').click();
    cy.get('[data-test="two-factor-password"] input').type('password', { log: false });
    cy.get('[data-test="two-factor-continue"]').click();

    cy.get('[data-test="two-factor-qr"] svg').should('exist');
    cy.get('[data-test="two-factor-secret"]')
      .invoke('text')
      .then((secret) => {
        cy.wrap(secret.trim()).as('secret');
        cy.totp(secret.trim()).then((code) => cy.fillOtp(code));
      });
    cy.get('[data-test="two-factor-confirm"]').click();

    cy.get('[data-test="recovery-code"]').should('have.length', 10);
    cy.get('[data-test="recovery-done"]').should('be.disabled');
    cy.get('[data-test="recovery-ack"] input').check();
    cy.get('[data-test="recovery-code"]')
      .first()
      .invoke('text')
      .then((code) => cy.wrap(code.trim()).as('recoveryCode'));
    cy.get('[data-test="recovery-done"]').click();
    cy.get('[data-test="two-factor-state"]').should(
      'contain.text',
      'Two-factor is on. 10 recovery codes left.',
    );

    // The codes are never shown again.
    cy.reload();
    cy.get('[data-test="recovery-code"]').should('not.exist');
  });

  it('signs in with a code, trusts the browser, and then signs in without one', () => {
    cy.signInThroughForm('maya@acme.dev');
    cy.location('pathname').should('eq', '/two-factor-challenge');
    cy.get('[data-test="challenge-account"]').should('contain.text', 'ma••@acme.dev');

    // A pending sign-in is not a sign-in.
    cy.request({ url: '/acme/storefront/production/overview', followRedirect: false })
      .its('status')
      .should('eq', 302);

    cy.get('[data-test="challenge-trust"] input').check();
    cy.totp().then((code) => cy.fillOtp(code));
    cy.location('pathname').should('eq', '/acme/storefront/production/overview');

    cy.get(selectors.shell.userMenu).click();
    cy.get('[data-test="menu-item-signout"]').click();
    cy.location('pathname').should('eq', '/signin');

    cy.get(login.email).type('maya@acme.dev');
    cy.get(login.password).type('password', { log: false });
    cy.get(login.submit).click();
    cy.location('pathname').should('eq', '/acme/storefront/production/overview');
  });

  it('refuses a wrong code, then accepts a recovery code once', () => {
    cy.signInThroughForm('jon@acme.dev');
    cy.location('pathname').should('eq', '/two-factor-challenge');

    cy.fillOtp('000000');
    cy.get('[data-test="challenge-error"]').should('contain.text', 'That code is not right.');

    cy.get('[data-test="challenge-toggle"]').click();
    cy.get('[data-test="recovery-input"] input').type('abcde-fghij');
    cy.get('[data-test="challenge-submit"]').click();
    cy.location('pathname').should('match', /^\/acme\//);

    cy.signInThroughForm('jon@acme.dev');
    cy.get('[data-test="challenge-toggle"]').click();
    cy.get('[data-test="recovery-input"] input').type('abcde-fghij');
    cy.get('[data-test="challenge-submit"]').click();
    cy.get('[data-test="challenge-error"]').should('contain.text', 'That code is not right.');
  });

  it('ends the attempt after five wrong codes', () => {
    cy.signInThroughForm('maya@acme.dev');

    for (let attempt = 0; attempt < 5; attempt++) {
      cy.fillOtp('000000');
      if (attempt < 4) cy.get('[data-test="challenge-error"]').should('be.visible');
    }

    cy.location('pathname').should('eq', '/signin');
    cy.get(login.error).should('contain.text', 'Too many wrong codes');
  });
});
