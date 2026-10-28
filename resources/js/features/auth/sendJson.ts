/**
 * Sends a JSON request from a signed-in page, with the CSRF token Laravel put in a cookie. A 422 becomes an ApiError whose
 * `fields` maps each field to its messages (from the error envelope's `details.fields`); any other failure becomes an
 * ApiError with no fields and a general message.
 */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly fields: Record<string, string[]> = {},
  ) {
    super(message);
  }

  first(field: string): string | undefined {
    return this.fields[field]?.[0];
  }
}

interface Envelope {
  error?: { message?: string; details?: { fields?: Array<{ path: string; message: string }> } };
}

function xsrfToken(): string {
  return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

export async function sendJson<T>(
  method: 'POST' | 'PUT' | 'DELETE',
  url: string,
  body: Record<string, unknown> = {},
): Promise<T> {
  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': xsrfToken(),
    },
    body: JSON.stringify(body),
  });

  if (response.ok) return (await response.json()) as T;

  let envelope: Envelope = {};
  try {
    envelope = (await response.json()) as Envelope;
  } catch {
    // Not JSON; fall through to the general message.
  }

  const fields: Record<string, string[]> = {};
  for (const field of envelope.error?.details?.fields ?? []) {
    (fields[field.path] ??= []).push(field.message);
  }

  throw new ApiError(
    envelope.error?.message ?? 'Something went wrong. Try again.',
    response.status,
    fields,
  );
}
