import { selectors } from '../support/selectors';

const { gallery } = selectors;

const visitGallery = (theme?: 'light' | 'dark') =>
  cy.visit('/_dev/ui', {
    onBeforeLoad(win) {
      if (theme) win.localStorage.setItem('bitflag-theme', theme);
    },
  });

describe('component gallery', () => {
  it('renders in light', () => {
    visitGallery('light');
    cy.get('html').should('have.attr', 'data-theme', 'light');
    cy.get('[data-test="ui-gallery"]').should('be.visible');
    cy.contains('h2', 'Buttons').should('be.visible');
  });

  it('renders in dark', () => {
    visitGallery('dark');
    cy.get('html').should('have.attr', 'data-theme', 'dark');
    cy.get('[data-test="ui-gallery"]').should('be.visible');
    cy.contains('h2', 'Buttons').should('be.visible');
  });

  it('runs the confirm dialog by keyboard and returns focus to its trigger', () => {
    visitGallery('light');
    cy.get(gallery.confirmButton).focus().type('{enter}');
    cy.get(gallery.dialog).should('be.visible');
    cy.get(gallery.confirmSubmit).should('be.disabled');

    cy.get(gallery.confirmInput).type('new-checkou');
    cy.get(gallery.confirmSubmit).should('be.disabled');
    cy.get(gallery.confirmInput).type('t');
    cy.get(gallery.confirmSubmit).should('be.enabled');

    cy.get(gallery.confirmInput).type('{esc}');
    cy.get(gallery.dialog).should('not.exist');
    cy.focused().should('have.attr', 'data-test', 'gallery-confirm');
  });

  it('keeps a row menu whole when it opens inside the scrolling table', () => {
    visitGallery('light');
    cy.get(gallery.table)
      .first()
      .within(() => {
        cy.get('[data-test="icon-button"]').first().click();
      });

    cy.get(gallery.menu).should('be.visible');
    cy.get(gallery.menu).then(($menu) => {
      expect($menu.closest(gallery.table).length, 'menu is not inside the table').to.eq(0);
      const box = $menu[0]!.getBoundingClientRect();
      expect(box.left).to.be.at.least(0);
      expect(box.top).to.be.at.least(0);
      expect(box.right).to.be.at.most(Cypress.config('viewportWidth'));
      expect(box.bottom).to.be.at.most(Cypress.config('viewportHeight'));
    });
    cy.get(gallery.menu).find('[data-test="menu-item-edit"]').should('be.visible');
  });

  it('removes a toast after four seconds on a frozen clock', () => {
    cy.clock();
    visitGallery('light');
    cy.get(gallery.toastButton).click();
    cy.get(gallery.toast).should('contain.text', 'Rollout updated in production');

    cy.tick(3900);
    cy.get(gallery.toast).should('exist');
    cy.tick(200);
    cy.get(gallery.toast).should('not.exist');
  });
});
