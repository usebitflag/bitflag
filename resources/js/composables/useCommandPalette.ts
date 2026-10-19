import { computed, ref } from 'vue';

export interface Command {
  id: string;
  label: string;
  group: string;
  run: () => void;
}

export type CommandProvider = (query: string) => Command[];

const providers = new Map<string, CommandProvider>();
const isOpen = ref(false);
const query = ref('');

/**
 * The command palette collects results from providers. The shell registers a "Go to" provider; later features register
 * search providers for flags, segments and the audit log.
 */
export function useCommandPalette() {
  function registerProvider(name: string, provider: CommandProvider): () => void {
    providers.set(name, provider);
    return () => providers.delete(name);
  }

  const results = computed(() =>
    [...providers.values()].flatMap((provider) => provider(query.value.trim().toLowerCase())),
  );

  function open(): void {
    query.value = '';
    isOpen.value = true;
  }

  function close(): void {
    isOpen.value = false;
  }

  function toggle(): void {
    if (isOpen.value) close();
    else open();
  }

  return { isOpen, query, results, open, close, toggle, registerProvider };
}

/** For tests: forget providers and close the palette. */
export function resetCommandPaletteForTests(): void {
  providers.clear();
  isOpen.value = false;
  query.value = '';
}
