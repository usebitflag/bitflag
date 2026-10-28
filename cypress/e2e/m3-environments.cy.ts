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

describe('environment settings', () => {
  beforeEach(() => cy.resetDemo());

  it('validates origins, saves protection and shows the new policy version', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/environments/staging/settings');

    cy.get('[data-test="origins-empty"]').should('be.visible');
    cy.get('[data-test="origin-input"]').type('https://app.example.com/path{enter}');
    cy.get('[data-test="origin-error"]').should('contain', 'scheme://host');
    cy.get('[data-test="origin-input"]').clear().type('http://example.com{enter}');
    cy.get('[data-test="origin-error"]').should('contain', 'https');
    cy.get('[data-test="origin-input"]').clear().type('HTTPS://App.Example.com{enter}');
    cy.get('[data-test="origin-chip"]').should('contain', 'https://app.example.com');

    cy.get('[data-test="protection-approval"] [data-test="switch-root"]').should('be.disabled');
    cy.get('[data-test="protection-protected"] [data-test="switch-root"]').click();
    cy.get('[data-test="max-snapshot-age"]').select('1 hour');
    cy.get('[data-test="settings-save"]').click();

    cy.get('[data-test="settings-unsaved"]').should('not.exist');
    cy.visit('/acme/storefront/development/settings/environments/staging/overview');
    cy.get('[data-test="protected-tag"]').should('be.visible');
    cy.contains('Policy version 2').should('be.visible');
  });

  it('is read-only for a viewer', () => {
    cy.signInAs('ana@acme.dev');
    cy.visit('/acme/storefront/development/settings/environments/staging/settings');
    cy.get('[data-test="settings-readonly"]').should('be.visible');
    cy.get('[data-test="settings-save"]').should('not.exist');
    cy.get('[data-test="origin-input"]').should('not.exist');
  });
});
