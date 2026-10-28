import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import { resetToasts, useToast } from '../composables/useToast';
import type { SharedProps } from '../types/shared';
import PortalLayout from './PortalLayout.vue';

const page = reactive<{ url: string; props: SharedProps }>({
  url: '/acme/storefront/production/overview',
  props: undefined as unknown as SharedProps,
});

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => page,
  router: { visit: vi.fn(), reload: vi.fn(), post: vi.fn() },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

vi.mock('../composables/useRememberScope', () => ({ useRememberScope: vi.fn() }));

const stubs = {
  EnvironmentPicker: true,
  ProjectSwitcher: true,
  UserMenu: true,
  CommandPalette: true,
  TopBar: true,
};

function setProps(
  overrides: Partial<SharedProps['scope']> = {},
  flash: SharedProps['flash'] = { toast: null },
): void {
  page.props = {
    auth: {
      user: {
        id: 'usr_1',
        name: 'Maya Chen',
        email: 'maya@acme.test',
        initials: 'MC',
        emailVerified: true,
        hasPassword: true,
      },
    },
    scope: {
      organization: { id: 'org_1', slug: 'acme', name: 'Acme' },
      project: { id: 'prj_1', key: 'storefront', name: 'Storefront' },
      environment: {
        id: 'env_1',
        key: 'production',
        name: 'Production',
        color: 'green',
        revision: 4,
      },
      projectRole: 'owner',
      orgRole: 'owner',
      ...overrides,
    },
    environments: [],
    navigation: [],
    can: { 'org.manage': true },
    flash,
    requestId: null,
  };
}

describe('portal layout', () => {
  afterEach(resetToasts);

  it('shows the read-only notice to viewers only', () => {
    setProps({ projectRole: 'viewer' });
    expect(
      mount(PortalLayout, { global: { stubs } }).find('[data-test="viewer-notice"]').exists(),
    ).toBe(true);
    setProps({ projectRole: 'publisher' });
    expect(
      mount(PortalLayout, { global: { stubs } }).find('[data-test="viewer-notice"]').exists(),
    ).toBe(false);
  });

  it('turns a flashed toast into a toast once', () => {
    setProps({}, { toast: { message: 'Rollout updated in production', tone: 'accent' } });
    mount(PortalLayout, { global: { stubs } });
    const { toasts } = useToast();
    expect(toasts.map((toast) => toast.message)).toEqual(['Rollout updated in production']);
  });

  it('renders the page inside a main landmark with a skip link', () => {
    setProps();
    const layout = mount(PortalLayout, {
      slots: { default: '<p data-test="body">Hello</p>' },
      global: { stubs },
    });
    expect(layout.get('main#main [data-test="body"]').text()).toBe('Hello');
    expect(layout.get('a[href="#main"]').text()).toBe('Skip to content');
  });
});
