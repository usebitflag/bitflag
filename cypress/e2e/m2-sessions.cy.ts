describe('sessions', () => {
  beforeEach(() => cy.resetDemo());

  it('lists this device and ends the others', () => {
    cy.signInAs('jon@acme.dev');
    cy.visit('/account/security');
    cy.get('[data-test="sessions-panel"]').should('be.visible');
    cy.get('[data-test="session-this"]').should('exist');
    cy.get('[data-test="sessions-end-others"]').should('exist');
  });

  it('shows the sign-in dialog when the session has expired', () => {
    cy.signInAs('jon@acme.dev');
    cy.visit('/acme/storefront/development/overview');
    cy.clearCookies();
    cy.get('[data-test="nav-item"]').contains('Flags').click();
    cy.get('[data-test="session-expired-dialog"]').should('be.visible');
  });
});
