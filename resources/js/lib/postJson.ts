export class JsonRequestError extends Error {
  constructor(
    public readonly status: number,
    public readonly errors: Record<string, string>,
    message: string,
  ) {
    super(message);
  }
}

function xsrfToken(): string {
  const match = /(?:^|; )XSRF-TOKEN=([^;]*)/.exec(document.cookie);
  return match?.[1] ? decodeURIComponent(match[1]) : '';
}

/**
 * Posts JSON with the session's CSRF token and returns the JSON reply. Used where a response carries a one-time secret that
 * must not pass through Inertia props, flash data or the session. A validation failure throws a JsonRequestError holding the
 * message for each field.
 */
export async function postJson<T>(url: string, body: Record<string, unknown>): Promise<T> {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': xsrfToken(),
    },
    body: JSON.stringify(body),
  });

  if (response.ok) return (await response.json()) as T;

  const failure = (await response.json().catch(() => ({}))) as {
    message?: string;
    errors?: Record<string, string[] | string>;
    error?: { message?: string; details?: { fields?: { path: string; message: string }[] } };
  };
  const errors: Record<string, string> = {};
  for (const [field, messages] of Object.entries(failure.errors ?? {})) {
    errors[field] = Array.isArray(messages) ? (messages[0] ?? '') : messages;
  }
  for (const item of failure.error?.details?.fields ?? []) errors[item.path] = item.message;

  throw new JsonRequestError(
    response.status,
    errors,
    failure.error?.message ?? failure.message ?? 'Something went wrong.',
  );
}
