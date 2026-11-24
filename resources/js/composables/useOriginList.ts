import { computed, ref, type Ref } from 'vue';

export const MAX_ORIGINS = 20;

/**
 * The normalized origin for what was typed, or null when it is not a usable `scheme://host[:port]`. Mirrors the server's
 * rules, which stay the authority.
 */
export function normalizeOrigin(input: string): string | null {
  const text = input.trim().replace(/\/+$/, '');
  const match = /^(https?):\/\/([^/?#:@*\s]+)(?::(\d{1,5}))?$/i.exec(text);
  if (!match) return null;

  const scheme = (match[1] ?? '').toLowerCase();
  const host = (match[2] ?? '').toLowerCase();
  let port = match[3] ?? '';
  if (host.includes('..') || host.startsWith('.') || host.endsWith('.')) return null;
  if (port !== '' && (Number(port) < 1 || Number(port) > 65535)) return null;
  if ((scheme === 'https' && port === '443') || (scheme === 'http' && port === '80')) port = '';

  return `${scheme}://${host}${port === '' ? '' : `:${port}`}`;
}

/** Why an origin cannot be added to the list, or null when it can. */
export function originProblem(input: string, existing: readonly string[]): string | null {
  const origin = normalizeOrigin(input);
  if (origin === null) {
    return 'Use scheme://host, for example https://app.example.com. No path, query or wildcard.';
  }
  const host = origin.replace(/^https?:\/\//, '').replace(/:\d+$/, '');
  if (origin.startsWith('http://') && host !== 'localhost' && host !== '127.0.0.1') {
    return 'Use https. Plain http is only for localhost.';
  }
  if (existing.includes(origin)) return 'Already in the list';
  if (existing.length >= MAX_ORIGINS) return 'Use at most 20 origins.';
  return null;
}

/** The editable list behind the origins field: add on Enter, remove by chip, one inline error for the draft. */
export function useOriginList(origins: Ref<string[]>) {
  const draft = ref('');
  const error = ref<string | null>(null);
  const full = computed(() => origins.value.length >= MAX_ORIGINS);

  function add(): void {
    if (draft.value.trim() === '') return;
    const problem = originProblem(draft.value, origins.value);
    if (problem !== null) {
      error.value = problem;
      return;
    }
    origins.value = [...origins.value, normalizeOrigin(draft.value) as string];
    draft.value = '';
    error.value = null;
  }

  function remove(origin: string): void {
    origins.value = origins.value.filter((value) => value !== origin);
  }

  return { draft, error, full, add, remove };
}
