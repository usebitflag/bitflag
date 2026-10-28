describe('environment keys', () => {
  beforeEach(() => cy.resetDemo());

  function openKeys() {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/development/settings/environments/staging/keys');
  }

  it('creates a server key, shows it once and only the masked key after a reload', () => {
    openKeys();
    cy.get('[data-test="key-new"]').click();
    cy.get('[data-test="key-type-server"]').check({ force: true });
    cy.get('[data-test="key-next"]').click();
    cy.get('[data-test="key-label"]').type('Checkout API');
    cy.get('[data-test="key-create"]').click();

    cy.get('[data-test="secret-value"]')
      .invoke('text')
      .then((secret) => {
        expect(secret).to.match(/^bf_srv_stg_[A-Za-z0-9]{36}$/);

        cy.get('[data-test="secret-done"]').should('be.disabled');
        cy.get('body').type('{esc}');
        cy.get('[data-test="secret-reveal"]').should('be.visible');
        cy.get('[data-test="secret-ack"] input').check();
        cy.get('[data-test="secret-done"]').click();

        cy.reload();
        cy.get('[data-test="key-masked"]').should('contain', `••••${secret.slice(-4)}`);
        cy.contains(secret).should('not.exist');
      });
  });

  it('creates a management key that authenticates against the management API', () => {
    openKeys();
    cy.get('[data-test="key-new"]').click();
    cy.get('[data-test="key-type-management"]').check({ force: true });
    cy.get('[data-test="key-next"]').click();
    cy.get('[data-test="key-label"]').type('Release pipeline');
    cy.get('[data-test="key-create"]').click();

    cy.get('[data-test="secret-value"]')
      .invoke('text')
      .then((secret) => {
        cy.request({ url: '/api/v1/me', headers: { Authorization: `Bearer ${secret}` } })
          .its('body')
          .should('include', { type: 'management', label: 'Release pipeline' });
        cy.request({
          url: '/api/v1/me',
          headers: { Authorization: 'Bearer nope' },
          failOnStatusCode: false,
        })
          .its('status')
          .should('eq', 401);
      });
  });

  it('creates a browser key', () => {
    openKeys();
    cy.get('[data-test="key-new"]').click();
    cy.get('[data-test="key-type-browser"]').check({ force: true });
    cy.get('[data-test="key-next"]').click();
    cy.get('[data-test="browser-origins-note"]').should(
      'contain',
      'No allowed origins are set yet',
    );
    cy.get('[data-test="key-label"]').type('Web storefront');
    cy.get('[data-test="key-create"]').click();
    cy.get('[data-test="secret-value"]')
      .invoke('text')
      .should('match', /^bf_pub_stg_/);
  });

  it('rotates a key with an overlap and revokes it', () => {
    openKeys();
    cy.get('[data-test="key-new"]').click();
    cy.get('[data-test="key-next"]').click();
    cy.get('[data-test="key-label"]').type('Checkout API');
    cy.get('[data-test="key-create"]').click();
    cy.get('[data-test="secret-ack"] input').check();
    cy.get('[data-test="secret-done"]').click();

    cy.get('[data-test="key-menu"]').first().click();
    cy.contains('[role="menuitem"]', 'Rotate…').click();
    cy.get('[data-test="rotate-overlap"]').select('1 hour');
    cy.get('[data-test="rotate-confirm"]').click();
    cy.get('[data-test="secret-note"]').should(
      'contain',
      'Deploy it before the old key stops working',
    );
    cy.get('[data-test="secret-ack"] input').check();
    cy.get('[data-test="secret-done"]').click();

    cy.get('[data-test="key-row"]').should('have.length', 2);
    cy.contains('[data-test="key-row"]', '(old)').should('contain', 'Rotating');

    cy.get('[data-test="key-menu"]').first().click();
    cy.contains('[role="menuitem"]', 'Revoke…').click();
    cy.get('[data-test="revoke-cache"]').should(
      'contain',
      'keep serving it until they next reach BitFlag',
    );
    cy.get('[data-test="revoke-confirm"]').should('be.disabled');
    cy.get('[data-test="revoke-ack"] input').check();
    cy.get('[data-test="revoke-confirm"]').click();
    cy.contains('[data-test="key-row"]', 'Revoked').should('exist');
  });

  it('is read-only for a viewer', () => {
    cy.signInAs('ana@acme.dev');
    cy.visit('/acme/storefront/development/settings/environments/staging/keys');
    cy.get('[data-test="key-new"]').should('not.exist');
    cy.get('[data-test="settings-readonly"]').should('be.visible');
  });
});
