import { onBeforeUnmount, ref, watch, type Ref } from 'vue';

export type SlugState = 'idle' | 'checking' | 'available' | 'unavailable';

/**
 * Asks the server, once typing pauses, whether an organization URL name is free. Only the answer for the latest value is
 * kept, so a slow reply for an older one cannot overwrite it.
 */
export function useSlugAvailability(slug: Ref<string>, delay = 300) {
  const state = ref<SlugState>('idle');
  const reason = ref<string | null>(null);
  let timer: ReturnType<typeof setTimeout> | undefined;
  let latest = 0;

  watch(slug, (value) => {
    clearTimeout(timer);
    latest += 1;
    const ticket = latest;

    if (value.trim() === '') {
      state.value = 'idle';
      reason.value = null;
      return;
    }

    state.value = 'checking';
    timer = setTimeout(async () => {
      try {
        const response = await fetch(
          `/onboarding/slug-availability?slug=${encodeURIComponent(value)}`,
          {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          },
        );
        const body = (await response.json()) as { available: boolean; reason: string | null };
        if (ticket !== latest) return;
        state.value = body.available ? 'available' : 'unavailable';
        reason.value = body.reason;
      } catch {
        if (ticket === latest) state.value = 'idle';
      }
    }, delay);
  });

  onBeforeUnmount(() => clearTimeout(timer));

  return { state, reason };
}
