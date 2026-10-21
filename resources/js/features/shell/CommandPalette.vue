<script setup lang="ts">
import { PhMagnifyingGlass } from '@phosphor-icons/vue';
import { router } from '@inertiajs/vue3';
import { DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useCommandPalette } from '../../composables/useCommandPalette';
import { confirmLeave } from '../../composables/useNavigationGuard';
import type { NavigationItem } from '../../types/shared';

const props = defineProps<{ navigation: NavigationItem[] }>();

const palette = useCommandPalette();

// The shell offers "Go to" for every page in the sidebar; features register their own search providers.
const unregister = palette.registerProvider('navigation', (query) =>
  props.navigation
    .filter((item) => query === '' || item.label.toLowerCase().includes(query))
    .map((item) => ({
      id: `go-${item.key}`,
      label: `Go to ${item.label}`,
      group: 'Navigation',
      run: () => {
        if (confirmLeave()) router.visit(item.href);
      },
    })),
);

const grouped = computed(() => {
  const groups = new Map<string, typeof palette.results.value>();
  for (const command of palette.results.value)
    groups.set(command.group, [...(groups.get(command.group) ?? []), command]);
  return [...groups.entries()];
});

function run(id: string): void {
  const command = palette.results.value.find((candidate) => candidate.id === id);
  palette.close();
  command?.run();
}

function onKey(event: KeyboardEvent): void {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault();
    palette.toggle();
  }
}

onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey);
  unregister();
});
</script>

<template>
  <DialogRoot
    :open="palette.isOpen.value"
    @update:open="(value) => (value ? palette.open() : palette.close())"
  >
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-40 bg-black/40" />
      <DialogContent
        class="bg-popover border-divider shadow-lg fixed top-[15vh] left-1/2 z-50 w-[min(560px,92vw)] -translate-x-1/2 rounded-lg border"
        data-test="command-palette"
        aria-describedby=""
      >
        <DialogTitle class="sr-only">Command palette</DialogTitle>
        <div class="border-divider flex items-center gap-2 border-b px-3">
          <PhMagnifyingGlass :size="16" aria-hidden="true" />
          <input
            v-model="palette.query.value"
            type="search"
            autofocus
            aria-label="Search commands"
            placeholder="Search or jump to…"
            class="h-11 flex-1 bg-transparent text-md outline-none"
            data-test="command-input"
            @keydown.enter.prevent="palette.results.value[0] && run(palette.results.value[0].id)"
          />
        </div>
        <div class="max-h-80 overflow-auto p-1">
          <p
            v-if="palette.results.value.length === 0"
            class="text-muted px-3 py-4 text-sm"
            data-test="command-empty"
          >
            Nothing matches.
          </p>
          <section v-for="[group, commands] in grouped" :key="group" :aria-label="group">
            <h2 class="text-muted px-3 pt-2 pb-1 text-xs font-medium">{{ group }}</h2>
            <button
              v-for="command in commands"
              :key="command.id"
              type="button"
              class="hover:bg-hover focus-visible:bg-hover flex h-9 w-full items-center rounded-sm px-3 text-left text-sm"
              :data-test="`command-${command.id}`"
              @click="run(command.id)"
            >
              {{ command.label }}
            </button>
          </section>
        </div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
