import { selectors } from '../support/selectors';

const adminOrigin = Cypress.expose('ADMIN_ORIGIN') as string;

describe('admin host', () => {
  before(() => cy.resetDemo());

  it('shows the staff sign-in on the admin host', () => {
    cy.visit('/signin');
    cy.origin(adminOrigin, () => {
      cy.visit('/login');
      cy.contains('BitFlag Support').should('be.visible');
      cy.get('input[type="email"]').should('be.visible');
      cy.get('input[type="password"]').should('be.visible');
    });
  });

  it('does not open the admin console for a portal session', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/production/overview');
    cy.origin(adminOrigin, () => {
      cy.visit('/');
      cy.location('pathname').should('eq', '/login');
    });
  });

  it('opens the empty dashboard for the staff user, and the portal stays closed to that session', () => {
    cy.visit('/signin');
    const password = String(Cypress.expose('DEMO_PASSWORD'));

    cy.origin(adminOrigin, { args: { password } }, ({ password }) => {
      cy.visit('/login');
      cy.get('input[type="email"]').type('support@bitflag.test');
      cy.get('input[type="password"]').type(password, { log: false });
      cy.get('button[type="submit"]').click();
      cy.location('pathname').should('not.eq', '/login');
      cy.contains('BitFlag Support').should('be.visible');
    });

    // The staff session cookie belongs to the admin host only. The portal still asks for a portal sign-in.
    cy.visit('/');
    cy.location('pathname').should('eq', '/signin');
    cy.get(selectors.login.email).should('be.visible');
  });
});
