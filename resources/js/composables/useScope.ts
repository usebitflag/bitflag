import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { SharedProps } from '../types/shared';

/** The organization, project, environment and roles the current page is scoped to. */
export function useScope() {
  const page = usePage<SharedProps>();
  return computed(() => page.props.scope);
}
