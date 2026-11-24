<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { PhArchive, PhDownloadSimple, PhTrash } from '@phosphor-icons/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Button from '../../../components/ui/Button.vue';
import InlineNotice from '../../../components/ui/InlineNotice.vue';
import DeleteProjectDialog from '../../../features/settings/DeleteProjectDialog.vue';
import ProjectSettingsLayout from '../../../layouts/ProjectSettingsLayout.vue';
import type { SharedProps } from '../../../types/shared';

interface ExportRow {
  id: string;
  status: 'queued' | 'running' | 'ready' | 'failed' | 'expired';
  bytes: number | null;
  createdAt: number;
  expiresAt: number | null;
  downloadUrl: string | null;
}

const props = defineProps<{
  canManage: boolean;
  exports: ExportRow[];
  environmentCount: number;
  graceDays: number;
}>();

const page = usePage<SharedProps>();
const project = computed(() => page.props.scope.project);
const base = computed(() => {
  const { organization, environment } = page.props.scope;
  return `/${organization?.slug}/${project.value?.key}/${environment?.key}/settings/danger`;
});
const status = computed(() => project.value?.status ?? 'active');
const errors = computed<Record<string, string | undefined>>(() => page.props.errors ?? {});
const deleting = ref(false);
const dueShort = computed(() =>
  project.value?.deletionDueAt
    ? new Date(project.value.deletionDueAt * 1000).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
      })
    : '',
);

const busy = computed(() =>
  props.exports.some((item) => item.status === 'queued' || item.status === 'running'),
);
let timer: ReturnType<typeof setInterval> | undefined;

// An export is built in the background: look again every couple of seconds until it is done.
watch(
  busy,
  (isBusy) => {
    clearInterval(timer);
    if (isBusy) timer = setInterval(() => router.reload({ only: ['exports'] }), 2000);
  },
  { immediate: true },
);
onBeforeUnmount(() => clearInterval(timer));

function requestExport(): void {
  router.post(`${base.value}/export`, {}, { preserveScroll: true });
}

const exportLabel: Record<ExportRow['status'], string> = {
  queued: 'Preparing…',
  running: 'Preparing…',
  ready: 'Ready',
  failed: 'Failed',
  expired: 'Expired',
};

function size(bytes: number | null): string {
  return bytes === null ? '' : bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toFixed(1)} KB`;
}

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
    description="Actions that affect the whole project. Only owners can use them."
    :can-manage="canManage"
  >
    <InlineNotice
      v-if="errors.project"
      tone="danger"
      :title="errors.project"
      data-test="danger-error"
    />

    <div class="flex max-w-[820px] flex-col gap-3">
      <section
        class="bg-surface border-divider flex items-center justify-between gap-4 rounded-lg border p-[18px]"
        data-test="danger-export"
      >
        <div>
          <h2 class="font-heading text-[15px] font-medium">Export project</h2>
          <p class="text-text/70 text-sm">
            Download flags, segments, environments and revision history as JSON. SDK keys and tokens
            are never included.
          </p>
          <ul v-if="exports.length" class="mt-2 flex flex-col gap-1 text-sm" data-test="exports">
            <li
              v-for="item in exports"
              :key="item.id"
              class="flex items-center gap-3"
              data-test="export-row"
            >
              <span class="text-text/62">{{
                new Date(item.createdAt * 1000).toLocaleString()
              }}</span>
              <span data-test="export-status">{{ exportLabel[item.status] }}</span>
              <span class="text-text/62">{{ size(item.bytes) }}</span>
              <a
                v-if="item.downloadUrl"
                :href="item.downloadUrl"
                class="text-primary-ink hover:underline"
                data-test="export-download"
                >Download</a
              >
            </li>
          </ul>
        </div>
        <Button :disabled="!canManage || busy" data-test="export-request" @click="requestExport">
          <template #icon><PhDownloadSimple :size="16" aria-hidden="true" /></template>
          Export JSON
        </Button>
      </section>

      <section
        class="bg-surface border-divider flex items-center justify-between gap-4 rounded-lg border p-[18px]"
        data-test="danger-archive-row"
      >
        <div>
          <h2 class="font-heading text-[15px] font-medium">Archive project</h2>
          <p class="text-text/70 text-sm">
            Makes the project read-only. SDKs keep serving its last published snapshots, and keys
            can still be rotated and revoked. It still counts toward your plan.
          </p>
        </div>
        <Button
          v-if="status === 'archived'"
          :disabled="!canManage"
          data-test="danger-unarchive"
          @click="unarchive"
          >Unarchive</Button
        >
        <Button
          v-else
          :disabled="!canManage || status !== 'active'"
          data-test="danger-archive"
          @click="archive"
        >
          <template #icon><PhArchive :size="16" aria-hidden="true" /></template>
          Archive…
        </Button>
      </section>

      <section
        class="bg-surface border-divider flex items-center justify-between gap-4 rounded-lg border p-[18px]"
        data-test="danger-delete-row"
      >
        <div>
          <h2 class="font-heading text-[15px] font-medium">Delete project</h2>
          <p
            v-if="status === 'deletion_scheduled'"
            class="text-text/70 text-sm"
            data-test="already-scheduled"
          >
            Already scheduled for deletion on {{ dueShort }}.
          </p>
          <p v-else class="text-text/70 text-sm">
            Scheduled for {{ graceDays }} days from now, so you can change your mind. After that
            every SDK key stops working.
          </p>
        </div>
        <Button
          v-if="status === 'deletion_scheduled'"
          :disabled="!canManage"
          data-test="danger-restore"
          @click="restore"
          >Restore project</Button
        >
        <Button v-else :disabled="!canManage" data-test="danger-delete" @click="deleting = true">
          <template #icon><PhTrash :size="16" aria-hidden="true" /></template>
          Delete…
        </Button>
      </section>
    </div>

    <DeleteProjectDialog
      v-if="project"
      v-model:open="deleting"
      :project-name="project.name"
      :project-key="project.key"
      :action="`${base}/delete`"
      :environment-count="environmentCount"
      :grace-days="graceDays"
      :errors="errors"
    />
  </ProjectSettingsLayout>
</template>
