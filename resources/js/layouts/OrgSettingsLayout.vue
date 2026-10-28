<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ToastViewport from '../components/ui/ToastViewport.vue';
import ThemeSelector from '../features/shell/ThemeSelector.vue';
import type { SharedProps } from '../types/shared';

defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const organization = computed(() => page.props.scope.organization);
const isOwner = computed(() => page.props.scope.orgRole === 'owner');

// Members see General, Members and Projects; Security is for Org Owners.
const tabs = computed(() => {
  const slug = organization.value?.slug;
  if (!slug) return [];
  return [
    { key: 'general', label: 'General', href: `/${slug}/settings/general`, show: true },
    { key: 'members', label: 'Members', href: `/${slug}/settings/members`, show: true },
    { key: 'security', label: 'Security', href: `/${slug}/settings/security`, show: isOwner.value },
    { key: 'projects', label: 'Projects', href: `/${slug}/settings/projects`, show: true },
  ].filter((tab) => tab.show);
});
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
      <nav
        v-if="tabs.length"
        class="border-divider flex gap-1 border-b"
        aria-label="Organization settings"
      >
        <Link
          v-for="tab in tabs"
          :key="tab.key"
          :href="tab.href"
          :class="[
            '-mb-px border-b-2 px-3 py-2 text-sm',
            page.url.startsWith(tab.href)
              ? 'border-primary text-text font-medium'
              : 'text-muted hover:text-text border-transparent',
          ]"
          :aria-current="page.url.startsWith(tab.href) ? 'page' : undefined"
          :data-test="`settings-tab-${tab.key}`"
        >
          {{ tab.label }}
        </Link>
      </nav>
      <slot />
    </main>
    <ToastViewport />
  </div>
</template>
