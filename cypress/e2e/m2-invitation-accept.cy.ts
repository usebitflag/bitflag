const link = () => `/invitations/${String(Cypress.expose('DEMO_INVITATION_TOKEN'))}`;

describe('accepting an invitation', () => {
  beforeEach(() => cy.resetDemo());

  it('shows what is offered and creates the account for the invited address', () => {
    cy.visit(link());
    cy.get('[data-test="invitation-email"] input').should('have.value', 'dev@northwind.studio');
    cy.get('[data-test="invitation-projects"]').should('contain.text', 'Storefront');
    cy.get('[data-test="invitation-name"] input').type('Dev Northwind');
    cy.get('[data-test="invitation-password"] input').type('a long passphrase here', {
      log: false,
    });
    cy.get('[data-test="invitation-accept"]').click();
    cy.location('pathname').should('match', /^\/acme\/storefront\//);
  });

  it('stops a signed-in person with another address from taking it', () => {
    cy.signInAs('ana@acme.dev');
    cy.visit(link());
    cy.get('[data-test="wrong-account-message"]').should('contain.text', '@northwind.studio');
    cy.get('[data-test="wrong-account-switch"]').should('be.visible');
  });

  it('is unavailable after it is used', () => {
    cy.visit(link());
    cy.get('[data-test="invitation-name"] input').type('Dev Northwind');
    cy.get('[data-test="invitation-password"] input').type('a long passphrase here', {
      log: false,
    });
    cy.get('[data-test="invitation-accept"]').click();
    cy.get('[data-test="user-menu"]').click();
    cy.get('[data-test="menu-item-signout"]').click();
    cy.visit(link());
    cy.get('[data-test="invitation-unavailable-message"]').should('be.visible');
  });
});
