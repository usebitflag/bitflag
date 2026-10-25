<script setup lang="ts">
import { PhList, PhMagnifyingGlass } from '@phosphor-icons/vue';
import IconButton from '../../components/ui/IconButton.vue';
import Kbd from '../../components/ui/Kbd.vue';
import { useCommandPalette } from '../../composables/useCommandPalette';
import ThemeSelector from './ThemeSelector.vue';

defineProps<{ context: string; environmentName: string | null }>();
defineEmits<{ 'toggle-drawer': [] }>();

const palette = useCommandPalette();
</script>

<template>
  <header
    class="border-divider bg-bg sticky top-0 z-30 flex h-14 items-center gap-3 border-b px-4"
    data-test="top-bar"
  >
    <IconButton
      class="lg:hidden"
      label="Open navigation"
      data-test="drawer-toggle"
      @click="$emit('toggle-drawer')"
    >
      <PhList :size="16" aria-hidden="true" />
    </IconButton>
    <div class="flex min-w-0 flex-1 items-center gap-2 text-sm" data-test="top-bar-context">
      <slot name="context">
        <span class="truncate font-medium">{{ context }}</span>
        <span v-if="environmentName" class="text-muted truncate">· {{ environmentName }}</span>
      </slot>
    </div>
    <button
      type="button"
      class="border-control-border text-muted hover:bg-hover flex h-8 items-center gap-2 rounded-md border px-2.5 text-sm"
      aria-label="Search"
      data-test="search-button"
      @click="palette.open()"
    >
      <PhMagnifyingGlass :size="14" aria-hidden="true" />
      <span class="hidden md:inline">Search</span>
      <Kbd class="hidden md:inline-flex">⌘K</Kbd>
    </button>
    <ThemeSelector />
  </header>
</template>
