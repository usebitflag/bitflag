<script setup lang="ts">
import {
  PhArrowLeft,
  PhCaretRight,
  PhKey,
  PhShieldCheck,
  PhTrash,
  PhUser,
} from '@phosphor-icons/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import UserMenu from '../features/shell/UserMenu.vue';
import type { SharedProps } from '../types/shared';
import PortalLayout from './PortalLayout.vue';

const props = defineProps<{ title: string }>();

const page = usePage<SharedProps>();
const organization = computed(() => page.props.scope.organization);

const items = [
  { key: 'profile', label: 'Profile', icon: PhUser, href: '/account/profile' },
  { key: 'security', label: 'Security', icon: PhShieldCheck, href: '/account/security' },
  { key: 'tokens', label: 'API tokens', icon: PhKey, href: '/account/tokens' },
  { key: 'delete', label: 'Delete account', icon: PhTrash, href: '/account/delete' },
];
</script>

<template>
  <PortalLayout data-test="account-layout">
    <template #sidebar="{ expanded }">
      <div class="border-divider flex h-14 items-center gap-2.5 border-b px-3">
        <span
          class="bg-selection text-primary-ink grid h-8 w-8 shrink-0 place-items-center rounded-md"
          aria-hidden="true"
          ><PhUser :size="16"
        /></span>
        <span
          :class="['min-w-0 flex-1 flex-col leading-tight', expanded ? 'flex' : 'hidden xl:flex']"
        >
          <span class="text-text/55 text-xs">Personal</span>
          <span class="truncate text-md font-medium">Account settings</span>
        </span>
      </div>
      <div class="flex-1 overflow-y-auto">
        <Link
          :href="organization ? `/${organization.slug}` : '/'"
          class="text-text/60 hover:text-text mx-2 mt-2 flex items-center gap-2 px-2 py-1.5 text-[12.5px]"
          data-test="account-back"
        >
          <PhArrowLeft :size="14" aria-hidden="true" />
          <span :class="expanded ? '' : 'hidden xl:inline'">Back to projects</span>
        </Link>
        <nav aria-label="Account settings" class="flex flex-col gap-0.5 px-2 py-2">
          <Link
            v-for="item in items"
            :key="item.key"
            :href="item.href"
            :title="item.label"
            :aria-current="page.url.startsWith(item.href) ? 'page' : undefined"
            :class="[
              'flex h-8 items-center gap-2.5 rounded-md px-2 text-md',
              page.url.startsWith(item.href)
                ? 'bg-hover text-text font-medium shadow-[inset_2px_0_0_var(--bf-primary)]'
                : 'text-text/80 hover:bg-hover',
            ]"
            :data-test="`account-tab-${item.key}`"
          >
            <component :is="item.icon" :size="16" aria-hidden="true" />
            <span :class="expanded ? '' : 'hidden xl:inline'">{{ item.label }}</span>
          </Link>
        </nav>
      </div>
      <div v-if="page.props.auth.user" class="border-divider border-t">
        <UserMenu :user="page.props.auth.user" :organization-settings-href="undefined" />
      </div>
    </template>

    <template #context>
      <nav aria-label="Breadcrumb" class="text-text/62 flex items-center gap-1.5 text-sm">
        <span>Account settings</span>
        <PhCaretRight :size="11" aria-hidden="true" />
        <span class="text-text" aria-current="page">{{ props.title }}</span>
      </nav>
    </template>

    <div class="flex flex-col gap-[18px]">
      <header data-test="page-header">
        <h1 class="font-heading text-title" data-test="account-title">{{ title }}</h1>
      </header>
      <slot />
    </div>
  </PortalLayout>
</template>
