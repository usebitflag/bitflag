import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

const post = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', () => ({ router: { post } }));

import InviteDialog from './InviteDialog.vue';

const projects = [
  { id: 'prj_1', name: 'Storefront' },
  { id: 'prj_2', name: 'Checkout API' },
];

const wrappers: VueWrapper[] = [];

async function settle(): Promise<void> {
  await nextTick();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await nextTick();
}

async function open(props: Record<string, unknown> = {}): Promise<void> {
  wrappers.push(
    mount(InviteDialog, {
      props: {
        open: true,
        action: '/acme/settings/members/invitations',
        projects,
        canChooseOrgRole: true,
        ...props,
      },
      attachTo: document.body,
    }),
  );
  await settle();
}

const q = <T extends Element>(selector: string): T | null =>
  document.body.querySelector<T>(selector);

async function type(selector: string, value: string): Promise<void> {
  const element = q<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(selector);
  if (!element) throw new Error(`No ${selector}`);
  element.value = value;
  element.dispatchEvent(
    new Event(element instanceof HTMLSelectElement ? 'change' : 'input', { bubbles: true }),
  );
  await settle();
}

async function submit(): Promise<void> {
  q('form')?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  await settle();
}

describe('invite dialog', () => {
  beforeEach(() => post.mockReset());
  afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    document.body.innerHTML = '';
  });

  it('counts the people as addresses are typed and lists the ones that are not valid', async () => {
    await open();
    await type('[data-test="invite-emails"]', 'a@x.dev, b@x.dev, nope');

    expect(q('[data-test="invite-count"]')?.textContent).toContain('2 people will be invited.');
    expect(q('[data-test="invite-invalid"]')?.textContent).toContain('nope');
  });

  it('keeps the send button off until there is someone to invite', async () => {
    await open();
    expect(q('[data-test="invite-send"]')?.hasAttribute('disabled')).toBe(true);

    await type('[data-test="invite-emails"]', 'a@x.dev');
    expect(q('[data-test="invite-send"]')?.hasAttribute('disabled')).toBe(false);
  });

  it('stops at twenty people', async () => {
    await open();
    await type(
      '[data-test="invite-emails"]',
      Array.from({ length: 21 }, (_, i) => `p${i}@x.dev`).join(', '),
    );

    expect(q('[data-test="invite-send"]')?.hasAttribute('disabled')).toBe(true);
    expect(document.body.textContent).toContain('Invite up to 20 people at a time.');
  });

  it('sends the addresses, the organization role and the chosen projects with their roles', async () => {
    await open();
    await type('[data-test="invite-emails"]', 'a@x.dev');
    await type('[data-test="invite-org-role"]', 'owner');
    const second = document.body.querySelectorAll<HTMLInputElement>(
      '[data-test="invite-project"] input[type="checkbox"]',
    )[1];
    second?.click();
    await settle();
    await submit();

    expect(post).toHaveBeenCalledWith(
      '/acme/settings/members/invitations',
      { emails: 'a@x.dev', org_role: 'owner', projects: [{ id: 'prj_2', role: 'developer' }] },
      expect.any(Object),
    );
  });

  it('invites as a Member only when the person cannot choose the organization role', async () => {
    await open({ canChooseOrgRole: false, fixedProjectId: 'prj_1' });

    expect(q('[data-test="invite-org-role"]')).toBeNull();
    await type('[data-test="invite-emails"]', 'a@x.dev');
    await submit();

    expect(post.mock.calls[0]?.[1]).toMatchObject({
      org_role: 'member',
      projects: [{ id: 'prj_1', role: 'developer' }],
    });
  });

  it('shows the server errors', async () => {
    await open({ errors: { emails: 'a@x.dev: Already invited.' } });

    expect(document.body.textContent).toContain('a@x.dev: Already invited.');
  });
});
