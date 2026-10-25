import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const router = vi.hoisted(() => ({
  patch: vi.fn(),
  delete: vi.fn(),
  post: vi.fn(),
  visit: vi.fn(),
}));
const page = vi.hoisted(() => ({
  url: '/acme/storefront/production/settings/members',
  props: {
    scope: {
      organization: { id: 'org_1', slug: 'acme', name: 'Acme' },
      project: { id: 'prj_1', key: 'storefront', name: 'Storefront' },
      environment: {
        id: 'env_1',
        key: 'production',
        name: 'Production',
        color: 'violet',
        revision: null,
      },
      projectRole: 'owner' as string | null,
      orgRole: 'member',
    },
    errors: {} as Record<string, string>,
  },
}));

vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => null },
  usePage: () => page,
  router,
}));

import Members from './Members.vue';

const members = [
  {
    membershipId: 'm1',
    userId: 'u1',
    name: 'Maya Santos',
    email: 'maya@acme.dev',
    role: 'owner' as const,
    version: 1,
    twoFactor: true,
    lastActiveAt: null,
  },
  {
    membershipId: 'm2',
    userId: 'u2',
    name: 'Jon Reyes',
    email: 'jon@acme.dev',
    role: 'publisher' as const,
    version: 3,
    twoFactor: false,
    lastActiveAt: 1_760_000_000,
  },
];

function show(overrides: Record<string, unknown> = {}) {
  return mount(Members, {
    props: {
      members,
      roleCounts: { owner: 1, publisher: 1, developer: 0, viewer: 0 },
      invitations: [],
      canManage: true,
      currentUserId: 'u1',
      project: { id: 'prj_1', name: 'Storefront' },
      ...overrides,
    },
    global: {
      stubs: {
        PortalLayout: { template: '<div><slot /></div>' },
        InviteDialog: true,
        ConfirmDialog: true,
      },
    },
  });
}

describe('project members tab', () => {
  beforeEach(() => {
    Object.values(router).forEach((fn) => fn.mockReset());
    page.props.scope.projectRole = 'owner';
    page.props.errors = {};
  });

  it('shows the count of people in each role', () => {
    const view = show();

    expect(view.get('[data-test="role-count-owner"]').text()).toBe('1');
    expect(view.get('[data-test="role-count-publisher"]').text()).toBe('1');
    expect(view.get('[data-test="role-count-viewer"]').text()).toBe('0');
  });

  it('changes a role with the version the list was read at', async () => {
    const rows = show().findAll('[data-test="member-row"]');

    await rows[1]?.get('[data-test="member-role"]').setValue('developer');

    expect(router.patch).toHaveBeenCalledWith(
      '/acme/storefront/production/settings/members/m2',
      { role: 'developer', expected_version: 3 },
      expect.any(Object),
    );
  });

  it('does not let a manager change their own role or remove themselves, but offers leaving', () => {
    const own = show().findAll('[data-test="member-row"]')[0]!;

    expect(own.get('[data-test="member-role"]').attributes('disabled')).toBeDefined();
    expect(own.find('[data-test="member-remove"]').exists()).toBe(false);
    expect(own.find('[data-test="member-leave"]').exists()).toBe(true);
  });

  it('says what the role can and cannot do when read-only', () => {
    page.props.scope.projectRole = 'developer';
    const view = show({ canManage: false, currentUserId: 'u2' });

    expect(view.get('[data-test="members-readonly"]').text()).toContain(
      "You're a Developer. You can view this, but only owners can change it.",
    );
    expect(view.find('[data-test="members-invite"]').exists()).toBe(false);
    expect(
      view
        .findAll('[data-test="member-role"]')
        .every((select) => select.attributes('disabled') !== undefined),
    ).toBe(true);
    expect(view.find('[data-test="member-remove"]').exists()).toBe(false);
  });

  it('shows pending invitations with resend only for ones that were sent', async () => {
    const view = show({
      invitations: [
        {
          id: 'i1',
          email: 'a@x.dev',
          role: 'viewer',
          status: 'pending',
          inviter: 'Maya',
          expiresAt: 1_760_600_000,
        },
        {
          id: 'i2',
          email: 'b@x.dev',
          role: 'viewer',
          status: 'queued',
          inviter: 'Maya',
          expiresAt: null,
        },
      ],
    });
    const rows = view.findAll('[data-test="invitation-row"]');

    expect(rows).toHaveLength(2);
    expect(rows[1]?.find('[data-test="invitation-resend"]').exists()).toBe(false);

    await rows[0]?.get('[data-test="invitation-resend"]').trigger('click');
    await rows[0]?.get('[data-test="invitation-cancel"]').trigger('click');

    expect(router.post).toHaveBeenCalledWith(
      '/acme/storefront/production/settings/members/invitations/i1/resend',
      {},
      expect.any(Object),
    );
    expect(router.delete).toHaveBeenCalledWith(
      '/acme/storefront/production/settings/members/invitations/i1',
      expect.any(Object),
    );
  });
});
