describe('environments', () => {
  beforeEach(() => cy.resetDemo());

  it('creates an environment after staging, fills the key from the name and opens it', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/environments');
    cy.get('[data-test="environment-row"]').should('have.length', 3);

    cy.get('[data-test="environment-new"]').click();
    cy.get('[data-test="environment-name"]').type('Pre production');
    cy.get('[data-test="environment-key"]').should('have.value', 'pre-production');
    cy.get('[data-test="environment-key"]').clear().type('staging');
    cy.contains('Already used in this project').should('be.visible');
    cy.get('[data-test="environment-create"]').should('be.disabled');
    cy.get('[data-test="environment-key"]').clear().type('qa');
    cy.get('[data-test="environment-position"]').select('After Staging');
    cy.get('[data-test="color-indigo"]').check({ force: true });
    cy.get('[data-test="environment-create"]').click();

    cy.location('pathname').should('eq', '/acme/storefront/development/settings/environments/qa');
    cy.get('[data-test="never-published"]').should('be.visible');

    cy.visit('/acme/storefront/development/settings/environments');
    cy.get('[data-test="environment-row"]').eq(2).should('contain', 'qa');
  });

  it('moves an environment up and down', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/environments');

    cy.get('[data-test="environment-row"]').eq(2).find('[data-test="move-up"]').click();
    cy.get('[data-test="environment-row"]').eq(1).should('contain', 'production');
    cy.get('[data-test="environment-row"]').eq(1).find('[data-test="move-down"]').click();
    cy.get('[data-test="environment-row"]').eq(2).should('contain', 'production');
  });

  it('shows the list read-only to a viewer', () => {
    cy.signInAs('ana@acme.dev');
    cy.visit('/acme/storefront/development/settings/environments');
    cy.get('[data-test="environment-row"]').should('have.length', 3);
    cy.get('[data-test="environment-new"]').should('not.exist');
    cy.get('[data-test="move-up"]').should('not.exist');
    cy.get('[data-test="settings-readonly"]').should('be.visible');
  });
});
