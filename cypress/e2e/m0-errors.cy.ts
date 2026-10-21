import { selectors } from '../support/selectors';

const { error } = selectors;

describe('error pages', () => {
  before(() => cy.resetDemo());

  it('shows the 500 page with the same request ID as the response header', () => {
    let headerRequestId = '';
    cy.intercept('GET', '/_testing/fail', (req) => {
      req.headers['X-Testing-Token'] = String(Cypress.expose('TESTING_E2E_TOKEN'));
      req.continue((res) => {
        headerRequestId = String(res.headers['x-request-id']);
      });
    }).as('fail');

    cy.visit('/_testing/fail', { failOnStatusCode: false });
    cy.wait('@fail');

    cy.get(error.status).should('have.text', '500');
    cy.get(error.requestId)
      .invoke('text')
      .then((shown) => {
        expect(headerRequestId).to.match(/^req_[0-9a-z]{26}$/);
        expect(shown).to.eq(headerRequestId);
      });
  });

  it('shows 404 for a project key that does not exist', () => {
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/no-such-project/production/overview', { failOnStatusCode: false });
    cy.get(error.status).should('have.text', '404');
    cy.get(error.requestId).invoke('text').should('match', /^req_/);
  });

  it('shows 419 when a form arrives from another site without a session token', () => {
    // Laravel lets a same-origin browser request through on its Sec-Fetch-Site header, so the form is posted from the
    // admin host, which is another site, the way a forged or stale form would arrive. The session cookie is not sent
    // across sites, so the token cannot match.
    const adminOrigin = String(Cypress.expose('ADMIN_ORIGIN'));
    const target = `${String(Cypress.config('baseUrl'))}/signin`;

    cy.visit('/signin');
    cy.origin(adminOrigin, { args: { target } }, ({ target }) => {
      cy.visit('/login');
      cy.window().then((win) => {
        const form = win.document.createElement('form');
        form.method = 'POST';
        form.action = target;
        for (const [name, value] of [
          ['email', 'maya@acme.dev'],
          ['password', 'password'],
        ] as const) {
          const input = win.document.createElement('input');
          input.name = name;
          input.value = value;
          form.appendChild(input);
        }
        win.document.body.appendChild(form);
        form.submit();
      });
    });

    cy.get(error.status).should('have.text', '419');
    cy.get(error.requestId).invoke('text').should('match', /^req_/);
  });
});
