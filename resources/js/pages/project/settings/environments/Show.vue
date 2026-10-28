<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusTag from '../../../../components/ui/StatusTag.vue';
import Tag from '../../../../components/ui/Tag.vue';
import DeliveryTab from '../../../../features/environments/DeliveryTab.vue';
import HistoryTab from '../../../../features/environments/HistoryTab.vue';
import OverviewTab from '../../../../features/environments/OverviewTab.vue';
import type { EnvironmentRow, HistoryEntry } from '../../../../features/environments/types';
import SettingsTabs from '../../../../features/shell/SettingsTabs.vue';
import ProjectSettingsLayout from '../../../../layouts/ProjectSettingsLayout.vue';
import type { SharedProps } from '../../../../types/shared';

const props = defineProps<{
  environment: EnvironmentRow;
  tab: 'overview' | 'delivery' | 'history';
  history: HistoryEntry[];
  canManage: boolean;
}>();

const page = usePage<SharedProps>();
const base = computed(() => {
  const { organization, project, environment } = page.props.scope;
  return `/${organization?.slug}/${project?.key}/${environment?.key}/settings/environments`;
});

const tabs = computed(() =>
  [
    { key: 'overview', label: 'Overview' },
    { key: 'delivery', label: 'Delivery' },
    { key: 'history', label: 'History' },
  ].map((item) => ({
    ...item,
    href: `${base.value}/${props.environment.key}/${item.key}`,
    active: item.key === props.tab,
  })),
);
</script>

<template>
  <Head :title="environment.name" />
  <ProjectSettingsLayout
    tab="environments"
    :title="environment.name"
    :description="`The ${environment.key} environment.`"
    :can-manage="canManage"
  >
    <p class="text-sm">
      <Link :href="base" class="text-text/70 hover:underline" data-test="back-to-environments"
        >All environments</Link
      >
      <Tag v-if="environment.isProtected" variant="accent" class="ml-2">Protected</Tag>
      <StatusTag v-if="environment.status !== 'active'" status="Archived" class="ml-2" />
    </p>
    <SettingsTabs label="Environment" :items="tabs" />
    <OverviewTab v-if="tab === 'overview'" :environment="environment" />
    <DeliveryTab v-else-if="tab === 'delivery'" />
    <HistoryTab v-else :entries="history" />
  </ProjectSettingsLayout>
</template>
