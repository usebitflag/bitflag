<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import StatusTag from '../../../../components/ui/StatusTag.vue';
import Tag from '../../../../components/ui/Tag.vue';
import KeysTab from '../../../../features/credentials/KeysTab.vue';
import DeliveryTab from '../../../../features/environments/DeliveryTab.vue';
import HistoryTab from '../../../../features/environments/HistoryTab.vue';
import OverviewTab from '../../../../features/environments/OverviewTab.vue';
import SettingsTab from '../../../../features/environments/SettingsTab.vue';
import type { EnvironmentRow, HistoryEntry, KeyRow } from '../../../../features/environments/types';
import SettingsTabs from '../../../../features/shell/SettingsTabs.vue';
import ProjectSettingsLayout from '../../../../layouts/ProjectSettingsLayout.vue';
import type { SharedProps } from '../../../../types/shared';

const props = defineProps<{
  environment: EnvironmentRow;
  tab: 'overview' | 'keys' | 'delivery' | 'history' | 'settings';
  history: HistoryEntry[];
  keys: KeyRow[];
  keyCount: number;
  canManageKeys: boolean;
  allowedOrigins: string[];
  deleteBlockers: string[];
  activeEnvironmentCount: number;
  defaultMaxSnapshotAgeSeconds: number | null;
  canManage: boolean;
  approvalsAvailable: boolean;
  snapshotAges: number[];
  colors: string[];
}>();

const page = usePage<SharedProps>();
const base = computed(() => {
  const { organization, project, environment } = page.props.scope;
  return `/${organization?.slug}/${project?.key}/${environment?.key}/settings/environments`;
});

const tabs = computed(() =>
  [
    { key: 'overview', label: 'Overview' },
    { key: 'keys', label: props.keyCount > 0 ? `Keys ${props.keyCount}` : 'Keys' },
    { key: 'delivery', label: 'Delivery' },
    { key: 'history', label: 'History' },
    { key: 'settings', label: 'Settings' },
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
    :description="`${environment.key} · ${environment.firstPublishedAt === null ? 'Never published' : 'Published'}`"
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
    <KeysTab
      v-else-if="tab === 'keys'"
      :environment="environment"
      :keys="keys"
      :action="`${base}/${environment.key}/keys`"
      :can-manage="canManageKeys"
      :allowed-origins="allowedOrigins"
      :max-snapshot-age-seconds="defaultMaxSnapshotAgeSeconds"
    />
    <DeliveryTab v-else-if="tab === 'delivery'" />
    <HistoryTab v-else-if="tab === 'history'" :entries="history" />
    <SettingsTab
      v-else
      :environment="environment"
      :action="`${base}/${environment.key}/settings`"
      :can-manage="canManage"
      :approvals-available="approvalsAvailable"
      :colors="colors"
      :snapshot-ages="snapshotAges"
      :delete-blockers="deleteBlockers"
      :active-environment-count="activeEnvironmentCount"
    />
  </ProjectSettingsLayout>
</template>
