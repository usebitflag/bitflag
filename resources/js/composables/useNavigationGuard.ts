import { router } from '@inertiajs/vue3';

type Guard = () => boolean;

const guards = new Set<Guard>();
let installed = false;

export const LEAVE_MESSAGE = 'You have unsaved changes. Leave without saving?';

/** True while any registered editor has unsaved changes. */
export function hasUnsavedChanges(): boolean {
  return [...guards].some((guard) => guard());
}

/** Asks before leaving when something is unsaved. Returns whether leaving is allowed. */
export function confirmLeave(
  ask: (message: string) => boolean = (message) => window.confirm(message),
): boolean {
  return !hasUnsavedChanges() || ask(LEAVE_MESSAGE);
}

/**
 * Editors register a function that says whether they are dirty. Switching environment, project or organization, any
 * Inertia visit and closing the tab then ask before discarding the work.
 */
export function useNavigationGuard() {
  function register(isDirty: Guard): () => void {
    guards.add(isDirty);
    return () => guards.delete(isDirty);
  }

  return { register, hasUnsavedChanges, confirmLeave };
}

/** Connects the guard to Inertia visits and to leaving the page. Call once from the app entry. */
export function installNavigationGuard(): void {
  if (installed) return;
  installed = true;

  router.on('before', (event) => {
    if (event.detail.visit.method !== 'get') return;
    if (!confirmLeave()) event.preventDefault();
  });

  window.addEventListener('beforeunload', (event) => {
    if (!hasUnsavedChanges()) return;
    event.preventDefault();
    event.returnValue = '';
  });
}

/** For tests: forget every registered guard. */
export function resetNavigationGuardForTests(): void {
  guards.clear();
}
