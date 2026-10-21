<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ToastViewport from '../components/ui/ToastViewport.vue';
import ThemeSelector from '../features/shell/ThemeSelector.vue';
import type { SharedProps } from '../types/shared';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const organization = computed(() => page.props.scope.organization);
</script>

<template>
  <div class="bg-bg text-text min-h-screen" data-test="org-settings-layout">
    <header class="border-divider flex h-14 items-center gap-3 border-b px-4">
      <Link
        v-if="organization"
        :href="`/${organization.slug}`"
        class="text-sm font-medium"
        data-test="org-name"
        >{{ organization.name }}</Link
      >
      <span class="flex-1" />
      <ThemeSelector />
    </header>
    <main class="mx-auto flex max-w-4xl flex-col gap-4 p-6">
      <h1 class="font-heading text-title" data-test="org-settings-title">{{ title }}</h1>
      <slot />
    </main>
    <ToastViewport />
  </div>
</template>
