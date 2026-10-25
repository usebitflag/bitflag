const email = 'nia@startup.dev';
const password = 'correct horse battery';

describe('sign-up to a first project', () => {
  before(() => cy.resetDemo());
  beforeEach(() => cy.clearMailbox());

  it('checks the password as it is typed', () => {
    cy.visit('/signup');

    cy.get('[data-test="password-rule-length"]').should('have.attr', 'data-met', 'false');
    cy.get('[data-test="signup-email"] input').type(email);
    cy.get('[data-test="signup-password"] input').type('short');
    cy.get('[data-test="password-rule-length"]').should('have.attr', 'data-met', 'false');

    cy.get('[data-test="signup-password"] input').clear().type('password1234');
    cy.get('[data-test="password-rule-common"]').should('have.attr', 'data-met', 'false');

    cy.get('[data-test="signup-password"] input').clear().type(password);
    cy.get('[data-test="password-rule-length"]').should('have.attr', 'data-met', 'true');
    cy.get('[data-test="password-rule-common"]').should('have.attr', 'data-met', 'true');
    cy.get('[data-test="password-rule-email"]').should('have.attr', 'data-met', 'true');
  });

  it('creates the account, confirms the email, and goes from organization to project to team', () => {
    cy.visit('/signup');
    cy.get('[data-test="signup-email"] input').type(email);
    cy.get('[data-test="signup-password"] input').type(password, { log: false });
    cy.get('[data-test="signup-submit"]').click();

    cy.location('pathname').should('eq', '/signup/check-email');
    cy.get('[data-test="check-email-message"]').should('contain.text', 'ni••@startup.dev');

    // The confirmation email carries a signed link that works for the person signed in.
    cy.latestEmail(email, 'Confirm your email').then(({ link }) => {
      expect(link).to.match(/\/email\/verify\//);
      cy.wrap(link).as('verifyLink');
    });

    cy.get('[data-test="continue-setup"]').click();
    cy.location('pathname').should('eq', '/onboarding/organization');

    // 5c: the URL name follows the name until it is edited, and is checked as it is typed.
    cy.get('[data-test="org-name"] input').type('Startup Inc');
    cy.get('[data-test="org-slug"] input').should('have.value', 'startup-inc');
    cy.contains('Available').should('be.visible');
    cy.get('[data-test="team-size-small"]').check();
    cy.get('[data-test="org-continue"]').click();

    // 5e: the first project, from the three-environment template with production protected.
    cy.location('pathname').should('eq', '/onboarding/startup-inc/project');
    cy.get('[data-test="environment-row"]').should('have.length', 3);
    cy.contains('Production starts protected').should('be.visible');
    cy.get('[data-test="project-name"] input').type('Storefront');
    cy.get('[data-test="project-key"] input').should('have.value', 'storefront');
    cy.get('[data-test="project-create"]').click();

    // 5f: invitations wait until the email is confirmed.
    cy.location('pathname').should('eq', '/onboarding/startup-inc/team');
    cy.get('[data-test="team-unverified"]').should('be.visible');
    cy.get('[data-test="team-emails"] textarea').type('a@startup.dev, b@startup.dev, not-an-email');
    cy.get('[data-test="team-count"]')
      .should('contain.text', '2 people')
      .and('contain.text', 'not-an-email');
    cy.get('[data-test="team-send"]').click();

    // 5g: ready, once, then the overview.
    cy.location('pathname').should('eq', '/onboarding/startup-inc/ready');
    cy.get('[data-test="ready-steps"] li').should('have.length', 4);
    cy.get('[data-test="ready-go"]').click();
    cy.location('pathname').should('match', /^\/startup-inc\/storefront\/[a-z-]+\/overview$/);

    // The unverified banner offers to send the email again; the link confirms the address and releases the invitations.
    cy.get('[data-test="verify-email-banner"]').should('be.visible');
    cy.get('@verifyLink').then((link) => cy.visit(String(link)));
    cy.get('[data-test="toast"]').should('contain.text', 'Your email is confirmed.');
    cy.get('[data-test="verify-email-banner"]').should('not.exist');
    cy.latestEmail('a@startup.dev', 'invited you').its('text').should('contain', '/invitations/');
  });

  it('resumes the wizard on the right step when the person comes back', () => {
    cy.resetDemo();
    cy.visit('/signup');
    cy.get('[data-test="signup-email"] input').type('resume@startup.dev');
    cy.get('[data-test="signup-password"] input').type(password, { log: false });
    cy.get('[data-test="signup-submit"]').click();
    cy.visit('/onboarding');
    cy.get('[data-test="org-name"] input').type('Resume Co');
    cy.get('[data-test="org-continue"]').click();
    cy.location('pathname').should('eq', '/onboarding/resume-co/project');

    cy.visit('/');
    cy.location('pathname').should('eq', '/onboarding/resume-co/project');
    cy.visit('/onboarding/resume-co/ready');
    cy.location('pathname').should('eq', '/onboarding/resume-co/project');
  });

  it('answers the same for an address that already has an account', () => {
    cy.visit('/signup');
    cy.get('[data-test="signup-email"] input').type('ana@acme.dev');
    cy.get('[data-test="signup-password"] input').type(password, { log: false });
    cy.get('[data-test="signup-submit"]').click();

    cy.location('pathname').should('eq', '/signup/check-email');
    cy.get('[data-test="continue-setup"]').should('not.exist');
    cy.latestEmail('ana@acme.dev', 'already have a BitFlag account')
      .its('text')
      .should('contain', '/signin');
  });
});
