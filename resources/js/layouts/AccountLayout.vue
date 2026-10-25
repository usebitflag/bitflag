<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import PortalLayout from './PortalLayout.vue';

defineProps<{ title: string }>();

const page = usePage();

const tabs = [
  { key: 'profile', label: 'Profile', href: '/account/profile' },
  { key: 'security', label: 'Security', href: '/account/security' },
  { key: 'delete', label: 'Delete account', href: '/account/delete' },
];
</script>

<template>
  <PortalLayout :context="title">
    <div class="flex max-w-3xl flex-col gap-6">
      <h1 class="font-heading text-2xl" data-test="account-title">Account settings</h1>
      <nav class="border-divider flex gap-1 border-b" aria-label="Account settings">
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
          :data-test="`account-tab-${tab.key}`"
        >
          {{ tab.label }}
        </Link>
      </nav>
      <slot />
    </div>
  </PortalLayout>
</template>
