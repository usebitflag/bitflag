import { contrastOf } from '../support/contrast';
import { selectors } from '../support/selectors';

const { shell, theme } = selectors;

describe('theme', () => {
  before(() => cy.resetDemo());
  afterEach(() => cy.emulateColorScheme('none'));

  it('applies Light before the application loads, even on a dark operating system', () => {
    cy.emulateColorScheme('dark');
    // With every script blocked, only the inline pre-paint script can have set the theme.
    cy.blockAppScripts();
    cy.visit('/signin');
    cy.get('html').should('have.attr', 'data-theme', 'light');
  });

  it("keeps Maya's Dark choice across a reload, with no flash", () => {
    cy.emulateColorScheme('light');
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/production/overview');

    cy.get('html').should('have.attr', 'data-theme', 'light');
    cy.get(theme.dark).click();
    cy.get('html').should('have.attr', 'data-theme', 'dark');

    cy.blockAppScripts();
    cy.reload();
    cy.get('html').should('have.attr', 'data-theme', 'dark');
  });

  it('follows the operating system while System is selected', () => {
    cy.emulateColorScheme('light');
    cy.signInAs('maya@acme.dev');
    cy.visit('/acme/storefront/production/overview');

    cy.get(theme.system).click();
    cy.get('html').should('have.attr', 'data-theme', 'light');

    cy.emulateColorScheme('dark');
    cy.get('html').should('have.attr', 'data-theme', 'dark');

    cy.emulateColorScheme('light');
    cy.get('html').should('have.attr', 'data-theme', 'light');
  });

  ['light', 'dark'].forEach((mode) => {
    it(`keeps filled buttons and semantic tags legible in ${mode}`, () => {
      cy.visit('/_dev/ui', {
        onBeforeLoad(win) {
          win.localStorage.setItem('bitflag-theme', mode);
        },
      });
      cy.get('html').should('have.attr', 'data-theme', mode);

      cy.get('[data-test="button"][data-variant="primary"]')
        .first()
        .then(($button) => {
          expect(contrastOf($button[0]!), 'primary button').to.be.at.least(4.5);
        });
      ['success', 'warning', 'danger'].forEach((variant) => {
        cy.get(`[data-test="tag"][data-variant="${variant}"]`)
          .first()
          .then(($tag) => {
            expect(contrastOf($tag[0]!), `${variant} tag`).to.be.at.least(4.5);
          });
      });
      cy.get('[data-test="banner"][data-tone="attention"]')
        .first()
        .then(($banner) => {
          expect(contrastOf($banner[0]!), 'attention banner').to.be.at.least(4.5);
        });
    });
  });
});

describe('shell layout', () => {
  before(() => cy.resetDemo());

  beforeEach(() => {
    cy.signInAs('maya@acme.dev');
  });

  const noHorizontalOverflow = () =>
    cy.document().then((doc) => {
      expect(doc.documentElement.scrollWidth, 'page scroll width').to.be.at.most(
        doc.documentElement.clientWidth,
      );
    });

  it('shows the 256px sidebar at 1280px without horizontal scrolling', () => {
    cy.viewport(1280, 800);
    cy.visit('/acme/storefront/production/overview');
    cy.get(shell.sidebar).then(($sidebar) => {
      expect($sidebar[0]!.getBoundingClientRect().width).to.eq(256);
    });
    cy.get(shell.userEmail).should('be.visible');
    noHorizontalOverflow();
  });

  it('collapses to a 56px rail at 1100px', () => {
    cy.viewport(1100, 800);
    cy.visit('/acme/storefront/production/overview');
    cy.get(shell.sidebar).then(($sidebar) => {
      expect($sidebar[0]!.getBoundingClientRect().width).to.eq(56);
    });
    cy.get(shell.drawerToggle).should('not.be.visible');
    noHorizontalOverflow();
  });

  it('turns the sidebar into a drawer below 1024px that works by keyboard', () => {
    cy.viewport(800, 800);
    cy.visit('/acme/storefront/production/overview');
    noHorizontalOverflow();

    // Closed: off screen and unreachable.
    cy.get(shell.sidebar).should('have.attr', 'inert');

    cy.get(shell.drawerToggle).focus().type('{enter}');
    cy.get(shell.sidebar).should('not.have.attr', 'inert');
    cy.get(shell.sidebar).should('be.visible');
    cy.focused().closest(shell.sidebar).should('exist');

    cy.focused().type('{esc}');
    cy.get(shell.sidebar).should('have.attr', 'inert');
    cy.focused().should('have.attr', 'data-test', 'drawer-toggle');
  });
});
