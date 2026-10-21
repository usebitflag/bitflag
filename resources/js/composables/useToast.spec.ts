import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TOAST_DURATION_MS, resetToasts, useToast } from './useToast';

describe('useToast', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => {
    resetToasts();
    vi.useRealTimers();
  });

  it('shows a toast for four seconds', () => {
    const { toast, toasts } = useToast();
    toast('Rollout updated in production');

    expect(TOAST_DURATION_MS).toBe(4000);
    expect(toasts).toHaveLength(1);
    expect(toasts[0]?.message).toBe('Rollout updated in production');

    vi.advanceTimersByTime(3999);
    expect(toasts).toHaveLength(1);
    vi.advanceTimersByTime(1);
    expect(toasts).toHaveLength(0);
  });

  it('pauses while hovered and resumes with the time that was left', () => {
    const { toast, toasts, pause, resume } = useToast();
    const id = toast('Saved');

    vi.advanceTimersByTime(3000);
    pause(id);
    vi.advanceTimersByTime(60_000);
    expect(toasts).toHaveLength(1);

    resume(id);
    vi.advanceTimersByTime(999);
    expect(toasts).toHaveLength(1);
    vi.advanceTimersByTime(1);
    expect(toasts).toHaveLength(0);
  });

  it('keeps an action and its tone', () => {
    const { toast, toasts } = useToast();
    const onClick = vi.fn();
    toast('Flag archived', { tone: 'accent', action: { label: 'Undo', onClick } });

    expect(toasts[0]?.tone).toBe('accent');
    toasts[0]?.action?.onClick();
    expect(onClick).toHaveBeenCalled();
  });

  it('dismisses one toast and leaves the others', () => {
    const { toast, toasts, dismiss } = useToast();
    const first = toast('One');
    toast('Two');

    dismiss(first);

    expect(toasts.map((t) => t.message)).toEqual(['Two']);
  });
});
