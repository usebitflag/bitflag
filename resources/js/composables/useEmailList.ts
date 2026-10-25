import { computed, type Ref } from 'vue';

const SHAPE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Reads a box of email addresses typed or pasted, separated by commas, semicolons, spaces or line breaks. It lower-cases,
 * drops repeats, and separates the ones that are not valid, for a live count. The server checks everything again.
 */
export function parseEmails(text: string): { valid: string[]; invalid: string[] } {
  const seen = new Set<string>();
  const valid: string[] = [];
  const invalid: string[] = [];

  for (const part of text.split(/[\s,;]+/)) {
    const email = part.replace(/^[<"']+|[>"']+$/g, '').toLowerCase();
    if (email === '' || seen.has(email)) continue;
    seen.add(email);
    (SHAPE.test(email) && email.length <= 254 ? valid : invalid).push(email);
  }

  return { valid, invalid };
}

export function useEmailList(text: Ref<string>, limit = 20) {
  const parsed = computed(() => parseEmails(text.value));

  return {
    valid: computed(() => parsed.value.valid),
    invalid: computed(() => parsed.value.invalid),
    count: computed(() => parsed.value.valid.length),
    overLimit: computed(() => parsed.value.valid.length > limit),
  };
}
