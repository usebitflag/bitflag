import { usePage } from '@inertiajs/vue3';
import { watch } from 'vue';
import type { SharedProps } from '../types/shared';

let lastSent = '';

function xsrfToken(): string {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return match?.[1] ? decodeURIComponent(match[1]) : '';
}

/** For tests: forget what was last sent. */
export function resetRememberScopeForTests(): void {
  lastSent = '';
}

/**
 * Tells the server which project and environment the person is working in, so the portal opens there next time.
 * It fires once per change of scope, in the background, and a failure is ignored: remembering is a convenience.
 */
export function useRememberScope(): void {
  const page = usePage<SharedProps>();

  watch(
    () =>
      [
        page.props.scope.organization?.id,
        page.props.scope.project?.id,
        page.props.scope.environment?.id,
      ] as const,
    ([organization, project, environment]) => {
      if (!organization || !project || !environment) return;
      const body = JSON.stringify({
        organization_id: organization,
        project_id: project,
        environment_id: environment,
      });
      if (body === lastSent) return;
      lastSent = body;

      void fetch('/scope/remember', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-XSRF-TOKEN': xsrfToken(),
        },
        body,
      }).catch(() => {
        lastSent = '';
      });
    },
    { immediate: true },
  );
}
