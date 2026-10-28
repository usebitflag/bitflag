describe('personal tokens', () => {
  beforeEach(() => cy.resetDemo());

  it('makes a token after confirming the password, shows it once and revokes it', () => {
    cy.signInAs('jon@acme.dev');
    cy.visit('/acme/storefront/development/settings/tokens');
    cy.get('[data-test="no-management-keys"]').should('be.visible');

    cy.get('[data-test="token-new"]').click();
    cy.get('[data-test="token-label"]').type('Laptop CLI');
    cy.get('[data-test="token-password"]').type('wrong password');
    cy.get('[data-test="token-create"]').click();
    cy.contains('That is not your password.').should('be.visible');

    cy.get('[data-test="token-password"]').clear().type('password');
    cy.get('[data-test="token-create"]').click();
    cy.get('[data-test="secret-value"]')
      .invoke('text')
      .should('match', /^bf_pat_[A-Za-z0-9]{36}$/);
    cy.get('[data-test="secret-ack"] input').check();
    cy.get('[data-test="secret-done"]').click();

    cy.reload();
    cy.get('[data-test="token-row"]').should('have.length', 1).and('contain', 'Laptop CLI');
    cy.get('[data-test="token-revoke"]').click();
    cy.get('[data-test="confirm-submit"]').click();
    cy.get('[data-test="token-row"]').should('contain', 'Revoked');

    cy.visit('/account/tokens');
    cy.get('[data-test="token-row"]').should('have.length', 1);
  });

  it('does not offer a viewer the access levels their role cannot have', () => {
    cy.signInAs('ana@acme.dev');
    cy.visit('/acme/storefront/development/settings/tokens');
    cy.get('[data-test="token-new"]').click();
    cy.get('[data-test="token-access"] option:disabled').should('have.length', 2);
  });
});
