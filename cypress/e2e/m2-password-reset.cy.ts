describe('password reset', () => {
  before(() => cy.resetDemo());
  beforeEach(() => cy.clearMailbox());

  it('answers the same for a known and an unknown address', () => {
    for (const address of ['ana@acme.dev', 'nobody@nowhere.dev']) {
      cy.visit('/forgot-password');
      cy.get('[data-test="forgot-email"] input').type(address);
      cy.get('[data-test="forgot-submit"]').click();
      cy.location('pathname').should('eq', '/forgot-password/sent');
      cy.get('[data-test="reset-sent-message"]').should('be.visible');
    }
  });

  it('sets a new password from the emailed link, which then works once', () => {
    cy.visit('/forgot-password');
    cy.get('[data-test="forgot-email"] input').type('ana@acme.dev');
    cy.get('[data-test="forgot-submit"]').click();

    cy.latestEmail('ana@acme.dev', 'Reset your password').then(({ link }) => {
      cy.visit(String(link));
      cy.get('[data-test="reset-password"] input').type('a brand new passphrase', { log: false });
      cy.get('[data-test="reset-submit"]').click();
      cy.location('pathname').should('eq', '/signin');

      cy.visit(String(link));
      cy.get('[data-test="reset-token-error"]').should('be.visible');
    });

    cy.signInThroughForm('ana@acme.dev', 'a brand new passphrase');
    cy.location('pathname').should('match', /^\/acme\//);
  });
});
