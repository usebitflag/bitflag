describe('sessions', () => {
  beforeEach(() => cy.resetDemo());

  it('lists this device and ends the others', () => {
    cy.signInAs('jon@acme.dev');
    cy.visit('/account/security');
    cy.get('[data-test="sessions-panel"]').should('be.visible');
    cy.get('[data-test="session-this"]').should('exist');
  });

  it('shows the sign-in dialog when the session has expired', () => {
    cy.signInAs('ana@acme.dev');
    cy.intercept('POST', '/scope/remember').as('remember');
    cy.visit('/acme/storefront/development/overview');
    cy.wait('@remember');
    // Eight days later the session is past its seven-day idle limit.
    cy.freezeServerClock('2026-11-02T12:00:00Z');
    cy.get('[data-test="nav-item"]').first().click();
    cy.contains('Your session ended').should('be.visible');
    cy.freezeServerClock(null);
    cy.get('[data-test="session-expired-password"] input').type(
      String(Cypress.expose('DEMO_PASSWORD')),
      {
        log: false,
      },
    );
    cy.get('[data-test="session-expired-submit"]').click();
    cy.contains('Your session ended').should('not.exist');
  });
});
