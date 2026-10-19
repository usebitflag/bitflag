import { reactive } from 'vue';

export type ToastTone = 'neutral' | 'accent';

export interface ToastAction {
  label: string;
  onClick: () => void;
}

export interface Toast {
  id: number;
  message: string;
  tone: ToastTone;
  action?: ToastAction;
}

export const TOAST_DURATION_MS = 4000;

const toasts = reactive<Toast[]>([]);
const timers = new Map<number, ReturnType<typeof setTimeout>>();
const remaining = new Map<number, { left: number; startedAt: number }>();
let nextId = 1;

function schedule(id: number, ms: number): void {
  remaining.set(id, { left: ms, startedAt: Date.now() });
  timers.set(
    id,
    setTimeout(() => dismiss(id), ms),
  );
}

export function dismiss(id: number): void {
  clearTimeout(timers.get(id));
  timers.delete(id);
  remaining.delete(id);
  const index = toasts.findIndex((toast) => toast.id === id);
  if (index !== -1) toasts.splice(index, 1);
}

/** Pauses the countdown while the pointer or focus is on the toast. */
export function pause(id: number): void {
  const state = remaining.get(id);
  if (!state || !timers.has(id)) return;
  clearTimeout(timers.get(id));
  timers.delete(id);
  remaining.set(id, {
    left: Math.max(0, state.left - (Date.now() - state.startedAt)),
    startedAt: Date.now(),
  });
}

export function resume(id: number): void {
  const state = remaining.get(id);
  if (!state || timers.has(id)) return;
  schedule(id, state.left);
}

export function resetToasts(): void {
  [...toasts].forEach((toast) => dismiss(toast.id));
}

/**
 * Shows a toast bottom right for four seconds. Name the object and environment in the message, for example
 * "Rollout updated in production".
 */
export function useToast() {
  function toast(
    message: string,
    options: { tone?: ToastTone; action?: ToastAction } = {},
  ): number {
    const id = nextId++;
    toasts.push({
      id,
      message,
      tone: options.tone ?? 'neutral',
      ...(options.action ? { action: options.action } : {}),
    });
    schedule(id, TOAST_DURATION_MS);
    return id;
  }

  return { toasts, toast, dismiss, pause, resume };
}
