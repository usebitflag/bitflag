import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const router = vi.hoisted(() => ({ patch: vi.fn(), delete: vi.fn(), post: vi.fn() }));
const page = vi.hoisted(() => ({
  url: '/acme/settings/members',
  props: {
    scope: { organization: { id: 'org_1', slug: 'acme', name: 'Acme' }, orgRole: 'owner' },
    errors: {} as Record<string, string>,
  },
}));

vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => null },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
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
    orgRole: 'owner' as const,
    twoFactor: true,
    lastActiveAt: 1_760_000_000,
    projects: [{ name: 'Storefront', role: 'owner' }],
  },
  {
    membershipId: 'm2',
    userId: 'u2',
    name: 'Jon Reyes',
    email: 'jon@acme.dev',
    orgRole: 'member' as const,
    twoFactor: false,
    lastActiveAt: null,
    projects: [],
  },
];
const invitations = [
  {
    id: 'i1',
    email: 'new@acme.dev',
    orgRole: 'member' as const,
    status: 'pending' as const,
    inviter: 'Maya Santos',
    expiresAt: 1_760_600_000,
    projects: [],
  },
  {
    id: 'i2',
    email: 'wait@acme.dev',
    orgRole: 'member' as const,
    status: 'queued' as const,
    inviter: 'Maya Santos',
    expiresAt: null,
    projects: [],
  },
];

function show(overrides: Record<string, unknown> = {}) {
  return mount(Members, {
    props: {
      members,
      invitations,
      projects: [],
      canManage: true,
      currentUserId: 'u1',
      ...overrides,
    },
    global: {
      stubs: {
        OrgSettingsLayout: { template: '<div><slot /></div>' },
        InviteDialog: true,
        ConfirmDialog: true,
      },
    },
  });
}

describe('organization members page', () => {
  beforeEach(() => {
    router.patch.mockReset();
    router.delete.mockReset();
    router.post.mockReset();
    page.props.errors = {};
  });

  it('lists each member with role, projects, two-factor and last activity', () => {
    const rows = show().findAll('[data-test="member-row"]');

    expect(rows).toHaveLength(2);
    expect(rows[0]?.text()).toContain('Maya Santos');
    expect(rows[0]?.get('[data-test="member-projects"]').text()).toBe('Storefront (owner)');
    expect(rows[1]?.get('[data-test="member-two-factor"]').text()).toBe('Off');
    expect(rows[1]?.get('[data-test="member-last-active"]').text()).toBe('Never');
  });

  it('lets a manager change another role but not their own, and offers removal only for others', () => {
    const rows = show().findAll('[data-test="member-row"]');

    expect(rows[0]?.get('[data-test="member-role"]').attributes('disabled')).toBeDefined();
    expect(rows[0]?.find('[data-test="member-remove"]').exists()).toBe(false);
    expect(rows[1]?.get('[data-test="member-role"]').attributes('disabled')).toBeUndefined();
    expect(rows[1]?.find('[data-test="member-remove"]').exists()).toBe(true);
  });

  it('changes a role by asking the server', async () => {
    const rows = show().findAll('[data-test="member-row"]');

    await rows[1]?.get('[data-test="member-role"]').setValue('owner');

    expect(router.patch).toHaveBeenCalledWith(
      '/acme/settings/members/m2',
      { role: 'owner' },
      expect.any(Object),
    );
  });

  it('shows no controls to a Member, only their own row', () => {
    const view = show({
      canManage: false,
      members: [members[1]],
      invitations: [],
      currentUserId: 'u2',
    });

    expect(view.find('[data-test="members-invite"]').exists()).toBe(false);
    expect(view.find('[data-test="member-role"]').exists()).toBe(false);
    expect(view.find('[data-test="pending-invitations"]').exists()).toBe(false);
    expect(view.get('[data-test="members-summary"]').text()).toContain('Your membership');
  });

  it('lists pending invitations, saying when one waits for the inviter to confirm their email', () => {
    const rows = show().findAll('[data-test="invitation-row"]');

    expect(rows).toHaveLength(2);
    expect(rows[1]?.get('[data-test="invitation-status"]').text()).toContain('confirm their email');
    expect(rows[1]?.find('[data-test="invitation-resend"]').exists()).toBe(false);
  });

  it('sends an invitation again and cancels one', async () => {
    const rows = show().findAll('[data-test="invitation-row"]');

    await rows[0]?.get('[data-test="invitation-resend"]').trigger('click');
    await rows[0]?.get('[data-test="invitation-cancel"]').trigger('click');

    expect(router.post).toHaveBeenCalledWith(
      '/acme/settings/members/invitations/i1/resend',
      {},
      expect.any(Object),
    );
    expect(router.delete).toHaveBeenCalledWith(
      '/acme/settings/members/invitations/i1',
      expect.any(Object),
    );
  });

  it('says why the last owner could not be changed', () => {
    page.props.errors = { member: 'This is the last owner. Make someone else an owner first.' };

    expect(show().get('[data-test="members-error"]').text()).toContain('last owner');
  });
});
