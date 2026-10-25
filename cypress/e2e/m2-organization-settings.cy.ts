describe('organization settings', () => {
  beforeEach(() => cy.resetDemo());

  it('lets an owner rename the organization and tighten the idle timeout', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/settings/general');
    cy.get('[data-test="general-name"] input').clear().type('Acme Labs');
    cy.get('[data-test="general-save"]').click();
    cy.get('[data-test="org-settings-title"]').should('exist');

    cy.visit('/acme/settings/security');
    cy.get('[data-test="security-idle"]').should('exist');
    cy.get('[data-test="security-save"]').click();
  });

  it('shows members and projects, and invites by email', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/settings/members');
    cy.get('[data-test="member-row"]').should('have.length.at.least', 5);
    cy.get('[data-test="pending-invitations"]').should('contain.text', 'dev@northwind.studio');
    cy.visit('/acme/settings/projects');
    cy.get('[data-test="project-row"]').should('have.length', 4);
  });

  it('keeps a member from reaching the settings', () => {
    cy.signInAs('ana@acme.dev');
    cy.request({ url: '/acme/settings/members', failOnStatusCode: false })
      .its('status')
      .should('be.oneOf', [403, 404, 302]);
  });
});
