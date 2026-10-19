import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { NavigationItem } from '../../types/shared';
import SidebarNav from './SidebarNav.vue';

vi.mock('@inertiajs/vue3', () => ({
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const item = (overrides: Partial<NavigationItem>): NavigationItem => ({
  key: 'overview',
  label: 'Overview',
  icon: 'house',
  group: 'Project',
  href: '/acme/storefront/production/overview',
  count: null,
  active: false,
  ...overrides,
});

describe('sidebar navigation', () => {
  it('lists only the groups it is given and marks the current page', () => {
    const nav = mount(SidebarNav, {
      props: {
        items: [
          item({ active: true }),
          item({ key: 'flags', label: 'Flags', group: 'Flags', count: 12 }),
        ],
      },
    });
    expect(nav.findAll('[data-test="nav-group"]')).toHaveLength(2);
    const current = nav
      .findAll('[data-test="nav-item"]')
      .filter((link) => link.attributes('aria-current') === 'page');
    expect(current).toHaveLength(1);
    expect(current[0]?.text()).toContain('Overview');
  });

  it('shows a count only when there is one, with an accessible label', () => {
    const nav = mount(SidebarNav, {
      props: { items: [item({}), item({ key: 'flags', label: 'Flags', count: 12 })] },
    });
    const counts = nav.findAll('[data-test="nav-count"]');
    expect(counts).toHaveLength(1);
    expect(counts[0]?.attributes('aria-label')).toBe('12 flags');
  });

  it('renders nothing but the landmark when there are no entries', () => {
    const nav = mount(SidebarNav, { props: { items: [] } });
    expect(nav.findAll('[data-test="nav-group"]')).toHaveLength(0);
  });
});
