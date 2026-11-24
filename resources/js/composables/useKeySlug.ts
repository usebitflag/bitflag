import { ref, watch, type Ref } from 'vue';

const PATTERN = /^[a-z][a-z0-9-]{0,39}$/;

/** The key an environment name suggests: lower case, dashes between words, starting with a letter. */
export function suggestKey(name: string): string {
  const slug = name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .replace(/^[^a-z]+/, '');
  return slug.slice(0, 40).replace(/-+$/, '');
}

/**
 * Keeps a key in step with the name it comes from until the person edits the key themselves. `problem` is the message for a
 * key that cannot be used, and null when it is free to use.
 */
export function useKeySlug(name: Ref<string>, key: Ref<string>, taken: Ref<readonly string[]>) {
  const edited = ref(false);

  watch(name, (value) => {
    if (!edited.value) key.value = suggestKey(value);
  });

  function setKey(value: string): void {
    edited.value = true;
    key.value = value;
  }

  function problem(): string | null {
    if (key.value === '') return null;
    if (!PATTERN.test(key.value)) return 'Start with a letter. Use a–z, 0–9 and -';
    if (taken.value.includes(key.value)) return 'Already used in this project';
    return null;
  }

  function reset(): void {
    edited.value = false;
  }

  return { edited, setKey, problem, reset };
}
