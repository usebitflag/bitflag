import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick, ref } from 'vue';
import { useSlugAvailability } from './useSlugAvailability';

function answer(body: { available: boolean; reason: string | null }): void {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ json: () => Promise.resolve(body) }));
}

function setup() {
  const slug = ref('');
  const scope = effectScope();
  const result = scope.run(() => useSlugAvailability(slug, 300))!;
  return { slug, result, scope };
}

describe('slug availability', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('stays idle for an empty name', async () => {
    const { slug, result } = setup();
    slug.value = 'acme';
    await nextTick();
    slug.value = '';
    await nextTick();

    expect(result.state.value).toBe('idle');
  });

  it('checks once typing pauses and reports a free name', async () => {
    answer({ available: true, reason: null });
    const { slug, result } = setup();

    slug.value = 'acme';
    await nextTick();
    expect(result.state.value).toBe('checking');
    expect(fetch).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(300);

    expect(fetch).toHaveBeenCalledWith(
      '/onboarding/slug-availability?slug=acme',
      expect.any(Object),
    );
    expect(result.state.value).toBe('available');
  });

  it('reports a name that cannot be used, with the reason', async () => {
    answer({ available: false, reason: 'That name is taken.' });
    const { slug, result } = setup();

    slug.value = 'acme';
    await nextTick();
    await vi.advanceTimersByTimeAsync(300);

    expect(result.state.value).toBe('unavailable');
    expect(result.reason.value).toBe('That name is taken.');
  });

  it('asks only about the latest value while typing continues', async () => {
    answer({ available: true, reason: null });
    const { slug } = setup();

    slug.value = 'a';
    await nextTick();
    await vi.advanceTimersByTimeAsync(100);
    slug.value = 'ac';
    await nextTick();
    await vi.advanceTimersByTimeAsync(300);

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(fetch).toHaveBeenCalledWith('/onboarding/slug-availability?slug=ac', expect.any(Object));
  });

  it('returns to idle when the request fails', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));
    const { slug, result } = setup();

    slug.value = 'acme';
    await nextTick();
    await vi.advanceTimersByTimeAsync(300);

    expect(result.state.value).toBe('idle');
  });
});
