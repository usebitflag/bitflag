import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, reactive, type EffectScope } from 'vue';
import { resetRememberScopeForTests, useRememberScope } from './useRememberScope';

const page = reactive({
  props: {
    scope: {
      organization: { id: 'org_a' } as { id: string } | null,
      project: { id: 'prj_a' } as { id: string } | null,
      environment: { id: 'env_a' } as { id: string } | null,
    },
  },
});

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

describe('useRememberScope', () => {
  const fetchMock = vi.fn();
  let scope: EffectScope;

  // The watcher outlives a test unless its scope is stopped, and would then react to the next test's changes.
  const start = () => scope.run(useRememberScope);

  beforeEach(() => {
    fetchMock.mockReset().mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = 'XSRF-TOKEN=abc%3D';
    page.props.scope = {
      organization: { id: 'org_a' },
      project: { id: 'prj_a' },
      environment: { id: 'env_a' },
    };
    resetRememberScopeForTests();
    scope = effectScope();
  });

  afterEach(() => {
    scope.stop();
    vi.unstubAllGlobals();
  });

  it('sends the public identifiers of the scope with the CSRF token', () => {
    start();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0]!;
    expect(url).toBe('/scope/remember');
    expect(JSON.parse(init.body)).toEqual({
      organization_id: 'org_a',
      project_id: 'prj_a',
      environment_id: 'env_a',
    });
    expect(init.headers['X-XSRF-TOKEN']).toBe('abc=');
  });

  it('sends again when the environment changes, and not when nothing changed', async () => {
    start();
    page.props.scope = { ...page.props.scope, environment: { id: 'env_b' } };
    await nextTick();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(JSON.parse(fetchMock.mock.calls[1]![1].body).environment_id).toBe('env_b');

    page.props.scope = { ...page.props.scope };
    await nextTick();
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('sends nothing without a full scope', () => {
    page.props.scope = { organization: { id: 'org_a' }, project: null, environment: null };
    start();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
