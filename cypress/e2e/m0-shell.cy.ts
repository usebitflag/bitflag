import { selectors } from '../support/selectors';

const { shell } = selectors;

describe('portal shell', () => {
  before(() => cy.resetDemo());

  it('signs Maya in and lands on the remembered scope', () => {
    cy.signInThroughForm('maya@acme.dev', undefined, { twoFactor: true });
    cy.location('pathname').should('eq', '/acme/storefront/production/overview');
    cy.get(shell.navItem)
      .filter('[aria-current="page"]')
      .should('have.length', 1)
      .and('contain.text', 'Overview');
    cy.get(shell.pageTitle).should('contain.text', 'Overview');
    cy.get(shell.userName).should('have.text', 'Maya Santos');
    cy.get(shell.userEmail).should('have.text', 'maya@acme.dev');
  });

  it('switches environment and keeps the page', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/production/overview');
    cy.get(shell.environmentKey).should('have.text', 'production');

    cy.get(shell.environmentPicker).click();
    cy.get('[data-test="environment-option-staging"]').click();

    cy.location('pathname').should('eq', '/acme/storefront/staging/overview');
    cy.get(shell.environmentKey).should('have.text', 'staging');
    cy.get(shell.environmentRevision).should('have.text', 'no revision');
  });

  it('lists the projects Maya can open and navigates between them', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/production/overview');

    cy.get(shell.projectSwitcher).click();
    cy.get(shell.switcherMenu)
      .should('contain.text', 'Storefront')
      .and('contain.text', 'Checkout API')
      .and('not.contain.text', 'Billing');
    cy.get('[data-test="switcher-project-acme-checkout-api"]').click();

    cy.location('pathname').should('match', /^\/acme\/checkout-api\/[a-z-]+\/overview$/);
    cy.get('[data-test="switcher-project"]').should('have.text', 'Checkout API');
  });

  it('remembers the scope after signing out and back in', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/production/overview');
    cy.get(shell.environmentPicker).click();
    cy.get('[data-test="environment-option-staging"]').click();
    cy.location('pathname').should('eq', '/acme/storefront/staging/overview');

    cy.get(shell.userMenu).click();
    cy.get('[data-test="menu-item-signout"]').click();
    cy.location('pathname').should('eq', '/signin');

    cy.signInThroughForm('maya@acme.dev', undefined, { twoFactor: true });
    cy.location('pathname').should('eq', '/acme/storefront/staging/overview');
  });

  it('shows Ana the read-only notice', () => {
    cy.signInAs('ana@acme.dev');
    cy.visit('/acme/storefront/production/overview');
    cy.get(shell.viewerNotice).should('be.visible').and('contain.text', 'Viewer');
  });

  it('shows Maya no read-only notice', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/production/overview');
    cy.get(shell.layout).should('exist');
    cy.get(shell.viewerNotice).should('not.exist');
  });

  it('gives Leo a 404 on Storefront and the no-access page at the root', () => {
    cy.signInAs('leo@acme.dev');

    cy.visit('/acme/storefront/production/overview', { failOnStatusCode: false });
    cy.get(selectors.error.status).should('have.text', '404');

    cy.visit('/');
    cy.location('pathname').should('eq', '/no-access');
    cy.get('[data-test="auth-title"]').should('contain.text', 'No projects yet');
  });

  it('keeps Gina out of Acme', () => {
    cy.signInAs('gina@globex.dev');

    cy.visit('/acme/storefront/production/overview', { failOnStatusCode: false });
    cy.get(selectors.error.status).should('have.text', '404');

    cy.visit('/');
    cy.location('pathname').should('eq', '/globex/billing/development/overview');
  });
});
