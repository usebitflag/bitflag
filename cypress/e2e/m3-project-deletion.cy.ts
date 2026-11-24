describe('project deletion', () => {
  beforeEach(() => cy.resetDemo());

  it('schedules a deletion with the key typed, shows the banner and restores', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/danger');

    cy.get('[data-test="danger-delete"]').click();
    cy.get('[data-test="project-delete-confirm"]').should('be.disabled');
    cy.get('[data-test="confirm-input"]').type('storefront');
    cy.get('[data-test="project-delete-confirm"]').click();

    cy.get('[data-test="project-status-banner"]')
      .should('contain', 'Storefront will be deleted on')
      .and('contain', 'Until then SDKs keep working.');
    cy.get('[data-test="project-restore"]').click();
    cy.get('[data-test="project-status-banner"]').should('not.exist');
  });

  it('archives and unarchives a project', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/danger');
    cy.get('[data-test="danger-archive"]').click();
    cy.get('[data-test="project-status-banner"]').should('contain', 'read-only');
    cy.get('[data-test="danger-unarchive"]').click();
    cy.get('[data-test="project-status-banner"]').should('not.exist');
  });

  it('is read-only for people who cannot manage the project', () => {
    cy.signInAs('ana@acme.dev');
    cy.visit('/acme/storefront/development/settings/danger');
    cy.get('[data-test="danger-delete"]').should('be.disabled');
    cy.get('[data-test="danger-archive"]').should('be.disabled');
  });
});

describe('project export', () => {
  beforeEach(() => cy.resetDemo());

  it('prepares an export and offers a download that holds no credentials', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/danger');

    cy.get('[data-test="export-request"]').click();
    cy.get('[data-test="export-download"]', { timeout: 20000 }).should('be.visible');
    cy.get('[data-test="export-status"]').first().should('contain', 'Ready');
    cy.get('[data-test="export-download"]')
      .invoke('attr', 'href')
      .then((href) => {
        cy.request(href as string).then((response) => {
          expect(response.body.project.key).to.eq('storefront');
          expect(JSON.stringify(response.body)).not.to.match(/bf_(srv|pub|mgt|pat)_/);
        });
      });
  });
});
