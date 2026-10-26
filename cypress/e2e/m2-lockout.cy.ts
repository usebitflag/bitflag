import { selectors } from '../support/selectors';

const { login } = selectors;

describe('sign-in lockout', () => {
  before(() => cy.resetDemo());
  beforeEach(() => cy.clearMailbox());

  it('locks an address after five wrong passwords, and says the same for an unknown one', () => {
    cy.intercept('POST', '/signin').as('signin');
    cy.visit('/signin');
    for (let attempt = 0; attempt < 5; attempt++) {
      cy.get(login.email).clear().type('leo@acme.dev');
      cy.get(login.password).clear().type('wrong password', { log: false });
      cy.get(login.submit).click();
      cy.wait('@signin');
      cy.get(login.error).should('be.visible');
    }

    // Even the right password is refused while locked, with the same message as a wrong one.
    cy.get(login.email).clear().type('leo@acme.dev');
    cy.get(login.password)
      .clear()
      .type(String(Cypress.expose('DEMO_PASSWORD')), { log: false });
    cy.get(login.submit).click();
    cy.wait('@signin');
    cy.get(login.error)
      .invoke('text')
      .then((locked) => {
        cy.get(login.email).clear().type('nobody@nowhere.dev');
        cy.get(login.password).clear().type('wrong password', { log: false });
        cy.get(login.submit).click();
        cy.wait('@signin');
        cy.get(login.error).invoke('text').should('eq', locked);
      });
    cy.location('pathname').should('eq', '/signin');

    cy.latestEmail('leo@acme.dev', 'temporarily locked')
      .its('text')
      .should('contain', '/forgot-password');
  });
});
