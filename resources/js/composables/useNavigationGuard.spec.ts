import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  LEAVE_MESSAGE,
  confirmLeave,
  resetNavigationGuardForTests,
  useNavigationGuard,
} from './useNavigationGuard';

vi.mock('@inertiajs/vue3', () => ({ router: { on: vi.fn() } }));

describe('navigation guard', () => {
  afterEach(resetNavigationGuardForTests);

  it('allows leaving without asking when nothing is dirty', () => {
    const ask = vi.fn();
    expect(confirmLeave(ask)).toBe(true);
    expect(ask).not.toHaveBeenCalled();
  });

  it('asks before leaving while an editor is dirty, and follows the answer', () => {
    useNavigationGuard().register(() => true);
    expect(confirmLeave(() => false)).toBe(false);
    const ask = vi.fn().mockReturnValue(true);
    expect(confirmLeave(ask)).toBe(true);
    expect(ask).toHaveBeenCalledWith(LEAVE_MESSAGE);
  });

  it('stops asking once the editor unregisters or is clean again', () => {
    let dirty = true;
    const unregister = useNavigationGuard().register(() => dirty);
    expect(useNavigationGuard().hasUnsavedChanges()).toBe(true);
    dirty = false;
    expect(useNavigationGuard().hasUnsavedChanges()).toBe(false);
    dirty = true;
    unregister();
    expect(useNavigationGuard().hasUnsavedChanges()).toBe(false);
  });
});
