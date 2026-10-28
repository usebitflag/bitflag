<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../../../components/ui/Button.vue';
import Card from '../../../components/ui/Card.vue';
import InlineNotice from '../../../components/ui/InlineNotice.vue';
import DeleteProjectDialog from '../../../features/settings/DeleteProjectDialog.vue';
import ProjectSettingsLayout from '../../../layouts/ProjectSettingsLayout.vue';
import type { SharedProps } from '../../../types/shared';

defineProps<{ canManage: boolean }>();

const page = usePage<SharedProps>();
const project = computed(() => page.props.scope.project);
const base = computed(() => {
  const { organization, environment } = page.props.scope;
  return `/${organization?.slug}/${project.value?.key}/${environment?.key}/settings/danger`;
});
const status = computed(() => project.value?.status ?? 'active');
const errors = computed<Record<string, string | undefined>>(() => page.props.errors ?? {});
const deleting = ref(false);

function archive(): void {
  router.post(`${base.value}/archive`, {}, { preserveScroll: true });
}
function unarchive(): void {
  router.post(`${base.value}/unarchive`, {}, { preserveScroll: true });
}
function restore(): void {
  router.post(`${base.value}/restore`, {}, { preserveScroll: true });
}
</script>

<template>
  <Head title="Danger zone" />
  <ProjectSettingsLayout
    tab="danger"
    title="Danger zone"
    description="Archive or delete this project. Deleting waits a few days so you can change your mind."
    :can-manage="canManage"
  >
    <InlineNotice
      v-if="errors.project"
      tone="danger"
      :title="errors.project"
      data-test="danger-error"
    />

    <Card title="Archive project">
      <p class="text-text/70 mb-3 text-sm">
        An archived project is read-only. SDKs keep serving its last published snapshots, and keys
        can still be rotated and revoked. It still counts toward your plan.
      </p>
      <Button
        v-if="status === 'archived'"
        :disabled="!canManage"
        data-test="danger-unarchive"
        @click="unarchive"
        >Unarchive project</Button
      >
      <Button
        v-else
        :disabled="!canManage || status !== 'active'"
        data-test="danger-archive"
        @click="archive"
        >Archive project</Button
      >
    </Card>

    <Card title="Delete project">
      <p class="text-text/70 mb-3 text-sm">
        The project is deleted after a grace period. Until then it is read-only and SDKs keep
        working. Only owners can do this.
      </p>
      <Button
        v-if="status === 'deletion_scheduled'"
        :disabled="!canManage"
        data-test="danger-restore"
        @click="restore"
        >Restore project</Button
      >
      <Button
        v-else
        variant="destructive"
        :disabled="!canManage"
        data-test="danger-delete"
        @click="deleting = true"
        >Delete project…</Button
      >
    </Card>

    <DeleteProjectDialog
      v-if="project"
      v-model:open="deleting"
      :project-name="project.name"
      :project-key="project.key"
      :action="`${base}/delete`"
      :errors="errors"
    />
  </ProjectSettingsLayout>
</template>
