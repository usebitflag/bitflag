import { onBeforeUnmount, ref } from 'vue';

/**
 * Holds a one-time secret for the panel that shows it. The secret lives in a local ref only: never in a store, the URL, browser
 * storage or an Inertia prop. `clear` and unmounting drop it, and the panel cannot close until it has been acknowledged.
 */
export function useSecretReveal() {
  const secret = ref<string | null>(null);
  const acknowledged = ref(false);

  function show(value: string): void {
    secret.value = value;
    acknowledged.value = false;
  }

  function clear(): void {
    secret.value = null;
    acknowledged.value = false;
  }

  onBeforeUnmount(clear);

  return { secret, acknowledged, show, clear };
}
