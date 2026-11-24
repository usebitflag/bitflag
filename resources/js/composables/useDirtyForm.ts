import { computed, onBeforeUnmount } from 'vue';
import { useNavigationGuard } from './useNavigationGuard';

/** What the composable needs from an Inertia form. */
export interface DirtyForm {
  isDirty: boolean;
  reset: () => void;
}

/**
 * Unsaved-changes handling for a settings form: `dirty` drives the "Unsaved changes" label and enables Save and Reset, and
 * the form is registered with the navigation guard so leaving the page asks first. The guard is released when the page
 * unmounts.
 */
export function useDirtyForm(form: DirtyForm) {
  const unregister = useNavigationGuard().register(() => form.isDirty);
  onBeforeUnmount(unregister);

  const dirty = computed(() => form.isDirty);

  return { dirty, reset: () => form.reset() };
}
