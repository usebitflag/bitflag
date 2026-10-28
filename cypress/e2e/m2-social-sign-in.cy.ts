describe('sign-up and sign-in with Google and GitHub', () => {
  before(() => cy.resetDemo());

  it('offers both providers on the sign-in and sign-up screens', () => {
    for (const path of ['/signin', '/signup']) {
      cy.visit(path);
      cy.get('[data-test="social-google"]')
        .should('contain.text', 'Google')
        .and('have.attr', 'href', '/auth/google/redirect');
      cy.get('[data-test="social-github"]')
        .should('contain.text', 'GitHub')
        .and('have.attr', 'href', '/auth/github/redirect');
    }
  });

  it('sends the browser to the provider with a state and a challenge', () => {
    cy.request({ url: '/auth/google/redirect', followRedirect: false }).then((response) => {
      expect(response.status).to.eq(302);
      const location = new URL(String(response.headers.location));
      expect(location.origin + location.pathname).to.eq(
        'https://accounts.google.com/o/oauth2/v2/auth',
      );
      expect(location.searchParams.get('state')).to.have.length(40);
      expect(location.searchParams.get('code_challenge_method')).to.eq('S256');
      expect(location.searchParams.get('redirect_uri')).to.match(/\/auth\/google\/callback$/);
    });
  });

  it('turns a return trip it did not start into a message on the sign-in page', () => {
    cy.visit('/auth/github/callback?state=not-the-state&code=abc');

    cy.location('pathname').should('eq', '/signin');
    cy.get('[data-test="login-error"]').should('contain.text', 'expired');
  });
});
