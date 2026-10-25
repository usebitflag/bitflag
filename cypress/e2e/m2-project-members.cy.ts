describe('project members', () => {
  beforeEach(() => cy.resetDemo());

  it('lists the people with access and offers invitations to an owner', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/members');
    cy.get('[data-test="page-title"]').should('be.visible');
    cy.get('[data-test="member-row"]').should('have.length.at.least', 3);
    cy.get('[data-test="members-invite"]').should('be.enabled');
  });

  it('is read-only for a viewer', () => {
    cy.signInAs('ana@acme.dev');
    cy.visit('/acme/storefront/development/settings/members');
    cy.get('[data-test="members-readonly"]').should('be.visible');
    cy.get('[data-test="members-invite"]').should('not.exist');
  });
});
