import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RevokeKeyDialog from './RevokeKeyDialog.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));

const credential = {
  id: 'key_x',
  type: 'server' as const,
  label: 'Checkout',
  masked: 'bf_srv_dev_••••ab12',
  prefix: 'bf_srv_dev_',
  last4: 'ab12',
  scopes: ['snapshot:read'],
  createdAt: 0,
  createdBy: { name: 'Maya', formerMember: false },
  lastUsedAt: null,
  lastUsedClient: null,
  expiresAt: null,
  state: 'active' as const,
  overlapEndsAt: null,
};

function render(maxSnapshotAgeSeconds: number | null) {
  return mount(RevokeKeyDialog, {
    attachTo: document.body,
    props: { open: true, credential, action: '/x/keys', maxSnapshotAgeSeconds },
  });
}

describe('RevokeKeyDialog', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('explains the cached snapshot for a 24 hour maximum age', async () => {
    render(86400);
    await flushPromises();
    expect(document.body.querySelector('[data-test="revoke-cache"]')!.textContent).toBe(
      'SDKs that already downloaded a snapshot keep serving it for up to 24 hours, then return fallback values.',
    );
    expect(document.body.textContent).toContain(
      'fail with 401 within a minute, usually right away',
    );
  });

  it('explains the cached snapshot when there is no maximum age', async () => {
    render(null);
    await flushPromises();
    expect(document.body.querySelector('[data-test="revoke-cache"]')!.textContent).toContain(
      'including after a restart if they use a persistent cache',
    );
  });

  it('keeps Revoke disabled until the person acknowledges', async () => {
    render(null);
    await flushPromises();
    const confirm = document.body.querySelector<HTMLButtonElement>('[data-test="revoke-confirm"]')!;
    expect(confirm.disabled).toBe(true);
    document.body.querySelector<HTMLInputElement>('[data-test="revoke-ack"] input')!.click();
    await flushPromises();
    expect(confirm.disabled).toBe(false);
  });
});
