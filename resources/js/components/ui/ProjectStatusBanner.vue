<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Banner from './Banner.vue';
import Button from './Button.vue';

const props = defineProps<{
  projectName: string;
  status: 'active' | 'archived' | 'deletion_scheduled';
  /** Unix seconds when a project waiting to be deleted is purged. */
  deletionDueAt: number | null;
  canRestore: boolean;
  /** `/org/project/env/settings/danger` */
  action: string;
}>();

const dueDate = computed(() =>
  props.deletionDueAt === null
    ? ''
    : new Date(props.deletionDueAt * 1000).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      }),
);

function restore(): void {
  router.post(`${props.action}/restore`, {}, { preserveScroll: true });
}

function unarchive(): void {
  router.post(`${props.action}/unarchive`, {}, { preserveScroll: true });
}
</script>

<template>
  <Banner
    v-if="status === 'deletion_scheduled'"
    tone="attention"
    label="Scheduled for deletion."
    data-test="project-status-banner"
    data-status="deletion_scheduled"
  >
    {{ projectName }} will be deleted on {{ dueDate }}. Until then SDKs keep working.
    <Button v-if="canRestore" size="sm" variant="ghost" data-test="project-restore" @click="restore"
      >Restore project</Button
    >
  </Banner>
  <Banner
    v-else-if="status === 'archived'"
    tone="neutral"
    label="Archived."
    data-test="project-status-banner"
    data-status="archived"
  >
    {{ projectName }} is read-only. SDKs keep serving its last published snapshots.
    <Button
      v-if="canRestore"
      size="sm"
      variant="ghost"
      data-test="project-unarchive"
      @click="unarchive"
      >Unarchive</Button
    >
  </Banner>
</template>
