import { router } from '@inertiajs/vue3';
import { readonly, ref } from 'vue';

export interface SessionExpiry {
  /** "Your session ended after 12 hours of inactivity." */
  message: string;
}

const expiry = ref<SessionExpiry | null>(null);

/** The session that ended, while the re-sign-in dialog is open. */
export const sessionExpiry = readonly(expiry);

export function dismissSessionExpiry(): void {
  expiry.value = null;
}

/**
 * Reads an error response and, when it says the session ended, returns what to tell the person. A 409 carries the
 * `SESSION_EXPIRED` envelope with the limit that applied; a 419 means the page's CSRF token no longer matches its session.
 */
export function readSessionExpiry(status: number, data: unknown): SessionExpiry | null {
  let body: unknown = data;

  if (typeof body === 'string') {
    try {
      body = JSON.parse(body);
    } catch {
      body = null;
    }
  }

  const error = (body as { error?: { code?: unknown; message?: unknown } } | null)?.error;

  if (status === 409 && error?.code === 'SESSION_EXPIRED') {
    return {
      message: typeof error.message === 'string' ? error.message : 'Your session ended.',
    };
  }

  return status === 419 ? { message: 'Your session ended.' } : null;
}

/**
 * Answers a session that ended with a dialog over the current page instead of leaving it, so what the person was
 * editing stays in memory. Call once at start-up.
 */
export function installSessionExpiry(): () => void {
  return router.on('httpException', (event) => {
    const { response } = event.detail;
    const found = readSessionExpiry(response.status, response.data);

    if (found === null) return;

    expiry.value = found;

    // Returning false stops Inertia showing the raw response.
    return false;
  });
}
