<script setup lang="ts">
import { PhCaretRight } from '@phosphor-icons/vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Banner from '../components/ui/Banner.vue';
import PageHeader from '../components/ui/PageHeader.vue';
import SettingsTabs from '../features/shell/SettingsTabs.vue';
import type { SharedProps } from '../types/shared';
import PortalLayout from './PortalLayout.vue';

const props = defineProps<{
  /** The tab that is open. */
  tab: 'general' | 'environments' | 'members' | 'tokens';
  title: string;
  description?: string;
  canManage: boolean;
}>();

const page = usePage<SharedProps>();
const scope = computed(() => page.props.scope);
const base = computed(() => {
  const { organization, project, environment } = scope.value;
  return `/${organization?.slug}/${project?.key}/${environment?.key}/settings`;
});

const tabs = computed(() =>
  [
    { key: 'general', label: 'General' },
    { key: 'environments', label: 'Environments' },
    { key: 'members', label: 'Members' },
    { key: 'tokens', label: 'API tokens' },
  ].map((item) => ({ ...item, href: `${base.value}/${item.key}`, active: item.key === props.tab })),
);

// The banner says what the person can do, by role.
const readOnlyText = computed(() => {
  const role = scope.value.projectRole;
  if (role === 'viewer') return "You're a Viewer. Everything here is read-only.";
  const label = role ? role.charAt(0).toUpperCase() + role.slice(1) : 'member';
  return `You're a ${label}. You can see the settings, but only owners can change them.`;
});
</script>

<template>
  <PortalLayout>
    <template #context>
      <nav aria-label="Breadcrumb" class="text-text/60 flex items-center gap-1.5 text-sm">
        <span>{{ scope.project?.name }}</span>
        <PhCaretRight :size="11" aria-hidden="true" />
        <span>Settings</span>
        <PhCaretRight :size="11" aria-hidden="true" />
        <span class="text-text" aria-current="page">{{ title }}</span>
      </nav>
    </template>
    <div class="flex flex-col gap-[18px]">
      <SettingsTabs label="Project settings" :items="tabs" />
      <PageHeader :title="title" :description="description">
        <template v-if="$slots.actions" #actions><slot name="actions" /></template>
      </PageHeader>
      <Banner
        v-if="!canManage && tab !== 'members'"
        tone="neutral"
        label="Read-only."
        data-test="settings-readonly"
        >{{ readOnlyText }}</Banner
      >
      <slot />
    </div>
  </PortalLayout>
</template>
