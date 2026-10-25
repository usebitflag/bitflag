import { onBeforeUnmount, ref } from 'vue';

/**
 * Counts whole seconds down to zero, for "resend" buttons. `restart` begins again from the full time.
 */
export function useCountdown(seconds: number) {
  const remaining = ref(seconds);
  let timer: ReturnType<typeof setInterval> | undefined;

  function stop(): void {
    if (timer !== undefined) clearInterval(timer);
    timer = undefined;
  }

  function restart(): void {
    stop();
    remaining.value = seconds;
    timer = setInterval(() => {
      remaining.value = Math.max(0, remaining.value - 1);
      if (remaining.value === 0) stop();
    }, 1000);
  }

  restart();
  onBeforeUnmount(stop);

  return { remaining, restart };
}
