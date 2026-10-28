describe('project settings', () => {
  beforeEach(() => cy.resetDemo());

  it('lets an owner edit General, reset and see the unsaved changes label', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings');
    cy.location('pathname').should('eq', '/acme/storefront/development/settings/general');

    cy.get('[data-test="general-save"]').should('be.disabled');
    cy.get('[data-test="project-name"]').clear().type('Web storefront');
    cy.get('[data-test="general-unsaved"]').should('be.visible');
    cy.get('[data-test="general-reset"]').click();
    cy.get('[data-test="project-name"]').should('have.value', 'Storefront');
    cy.get('[data-test="general-unsaved"]').should('not.exist');

    cy.get('[data-test="project-name"]').clear().type('Web storefront');
    cy.get('[data-test="stale-after"]').select('60');
    cy.get('[data-test="general-save"]').click();
    cy.get('[data-test="project-name"]').should('have.value', 'Web storefront');
    cy.get('[data-test="general-unsaved"]').should('not.exist');
  });

  it('asks before leaving with unsaved changes', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/general');
    cy.get('[data-test="project-name"]').clear().type('Changed');

    cy.on('window:confirm', (message) => {
      expect(message).to.contain('unsaved changes');
      return false;
    });
    cy.get('[data-test="settings-tab-members"]').click();
    cy.location('pathname').should('eq', '/acme/storefront/development/settings/general');
  });

  it('is read-only for a publisher and a viewer', () => {
    for (const email of ['jon@acme.dev', 'ana@acme.dev']) {
      cy.signInAs(email);
      cy.visit('/acme/storefront/development/settings/general');
      cy.get('[data-test="settings-readonly"]').should('be.visible');
      cy.get('[data-test="project-name"]').should('be.disabled');
      cy.get('[data-test="general-save"]').should('not.exist');
    }
  });
});
