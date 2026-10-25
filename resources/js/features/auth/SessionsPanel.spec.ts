import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const del = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { delete: (...args: unknown[]) => del(...args) } }));

import SessionsPanel from './SessionsPanel.vue';

const now = 1_760_000_000;
const sessions = [
  {
    ref: 'a'.repeat(32),
    device: 'Chrome on macOS',
    location: 'Unknown location',
    lastActive: now,
    startedAt: now,
    current: true,
  },
  {
    ref: 'b'.repeat(32),
    device: 'Safari on iOS',
    location: 'Berlin, Germany',
    lastActive: now,
    startedAt: now,
    current: false,
  },
];
const browsers = [
  {
    id: 'x'.repeat(26),
    device: 'Firefox on Linux',
    trustedAt: now,
    lastUsedAt: null,
    expiresAt: now + 100,
  },
];

describe('sessions panel', () => {
  beforeEach(() => del.mockReset());

  it('marks this device and offers sign-out only for the others', () => {
    const page = mount(SessionsPanel, { props: { sessions, trustedBrowsers: [] } });

    expect(page.get('[data-test="session-current"]').text()).toContain('This device');
    expect(
      page.get('[data-test="session-current"]').find('[data-test="session-end"]').exists(),
    ).toBe(false);
    expect(page.findAll('[data-test="session-row"] [data-test="session-end"]')).toHaveLength(1);
  });

  it('ends one session by its name, not its id', async () => {
    const page = mount(SessionsPanel, { props: { sessions, trustedBrowsers: [] } });

    await page.get('[data-test="session-row"] [data-test="session-end"]').trigger('click');

    expect(del).toHaveBeenCalledWith(`/account/sessions/${'b'.repeat(32)}`, expect.any(Object));
  });

  it('offers to sign out everywhere else only when there is somewhere else', async () => {
    const alone = mount(SessionsPanel, {
      props: { sessions: [sessions[0]!], trustedBrowsers: [] },
    });
    const several = mount(SessionsPanel, { props: { sessions, trustedBrowsers: [] } });

    expect(alone.find('[data-test="sessions-end-others"]').exists()).toBe(false);

    await several.get('[data-test="sessions-end-others"]').trigger('click');
    expect(del).toHaveBeenCalledWith('/account/sessions', expect.any(Object));
  });

  it('lists trusted browsers and removes one', async () => {
    const page = mount(SessionsPanel, { props: { sessions, trustedBrowsers: browsers } });

    expect(page.get('[data-test="trusted-row"]').text()).toContain('Firefox on Linux');
    await page.get('[data-test="trusted-revoke"]').trigger('click');

    expect(del).toHaveBeenCalledWith(
      `/account/trusted-browsers/${'x'.repeat(26)}`,
      expect.any(Object),
    );
  });

  it('says so when no browser is trusted', () => {
    const page = mount(SessionsPanel, { props: { sessions, trustedBrowsers: [] } });

    expect(page.get('[data-test="trusted-empty"]').text()).toBe('No browser is trusted.');
  });
});
