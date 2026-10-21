import { describe, expect, it, vi } from 'vitest';

const props = {
  can: { 'project.read': true, 'flags.draft.write': false },
  scope: {
    organization: null,
    project: null,
    environment: null,
    projectRole: 'viewer',
    orgRole: 'member',
  },
};

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props }) }));

describe('useCan', () => {
  it('allows only capabilities the server granted', async () => {
    const { useCan } = await import('./useCan');
    const can = useCan();

    expect(can('project.read')).toBe(true);
    expect(can('flags.draft.write')).toBe(false);
    expect(can('project.manage')).toBe(false);
  });
});

describe('useScope', () => {
  it('exposes the shared scope', async () => {
    const { useScope } = await import('./useScope');

    expect(useScope().value.projectRole).toBe('viewer');
    expect(useScope().value.orgRole).toBe('member');
  });
});
