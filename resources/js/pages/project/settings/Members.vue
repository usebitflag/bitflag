<script setup lang="ts">
import { PhCaretRight, PhUserPlus } from '@phosphor-icons/vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Banner from '../../../components/ui/Banner.vue';
import PageHeader from '../../../components/ui/PageHeader.vue';
import SettingsTabs from '../../../features/shell/SettingsTabs.vue';
import Button from '../../../components/ui/Button.vue';
import ConfirmDialog from '../../../components/ui/ConfirmDialog.vue';
import InlineNotice from '../../../components/ui/InlineNotice.vue';
import InviteDialog from '../../../features/members/InviteDialog.vue';
import MembersTable, { type ProjectMember } from '../../../features/members/MembersTable.vue';
import PendingInvites, {
  type ProjectInvitation,
} from '../../../features/members/PendingInvites.vue';
import RoleCards, { type ProjectRoleName } from '../../../features/members/RoleCards.vue';
import PortalLayout from '../../../layouts/PortalLayout.vue';
import type { SharedProps } from '../../../types/shared';

defineProps<{
  members: ProjectMember[];
  roleCounts: Record<ProjectRoleName, number>;
  invitations: ProjectInvitation[];
  canManage: boolean;
  currentUserId: string;
  project: { id: string; name: string };
}>();

const page = usePage<SharedProps>();
const scope = computed(() => page.props.scope);
const base = computed(() => {
  const { organization, project, environment } = scope.value;
  return `/${organization?.slug}/${project?.key}/${environment?.key}/settings/members`;
});
const role = computed(() => scope.value.projectRole);
const errors = computed<Record<string, string | undefined>>(() => page.props.errors ?? {});

const inviting = ref(false);
const removing = ref<ProjectMember | null>(null);
const leaving = ref(false);
const removeOpen = computed({
  get: () => removing.value !== null,
  set: (value) => {
    if (!value) removing.value = null;
  },
});

const readOnlyText = computed(() => {
  const label = role.value ? role.value.charAt(0).toUpperCase() + role.value.slice(1) : 'member';
  return `You're a ${label}. You can view this, but only owners can change it.`;
});

function changeRole(member: ProjectMember, next: ProjectRoleName): void {
  router.patch(
    `${base.value}/${member.membershipId}`,
    { role: next, expected_version: member.version },
    { preserveScroll: true },
  );
}

function confirmRemove(): void {
  const member = removing.value;
  if (member === null) return;
  router.delete(`${base.value}/${member.membershipId}`, {
    preserveScroll: true,
    onFinish: () => (removing.value = null),
  });
}

function leave(): void {
  const { organization, project } = scope.value;
  router.delete(`/${organization?.slug}/${project?.key}/leave`);
}

function resend(invitation: ProjectInvitation): void {
  router.post(`${base.value}/invitations/${invitation.id}/resend`, {}, { preserveScroll: true });
}

function cancel(invitation: ProjectInvitation): void {
  router.delete(`${base.value}/invitations/${invitation.id}`, { preserveScroll: true });
}
</script>

<template>
  <Head title="Members" />
  <PortalLayout>
    <template #context>
      <nav aria-label="Breadcrumb" class="text-text/60 flex items-center gap-1.5 text-sm">
        <span>{{ project.name }}</span>
        <PhCaretRight :size="11" aria-hidden="true" />
        <span>Settings</span>
        <PhCaretRight :size="11" aria-hidden="true" />
        <span class="text-text" aria-current="page">Members</span>
      </nav>
    </template>
    <div class="flex flex-col gap-[18px]">
      <SettingsTabs
        label="Project settings"
        :items="[{ key: 'members', label: 'Members', href: base, active: true }]"
      />
      <PageHeader
        title="Members"
        :description="`People with access to ${project.name}. A role applies to every environment. Protected environments can add a review step on top.`"
      >
        <template v-if="canManage" #actions>
          <Button variant="primary" data-test="members-invite" @click="inviting = true">
            <template #icon><PhUserPlus :size="16" aria-hidden="true" /></template>
            Invite people
          </Button>
        </template>
      </PageHeader>

      <Banner v-if="!canManage" tone="neutral" label="Read-only." data-test="members-readonly">{{
        readOnlyText
      }}</Banner>
      <InlineNotice
        v-if="errors.member"
        tone="danger"
        :title="errors.member"
        data-test="members-error"
      />
      <InlineNotice
        v-if="errors.invitation"
        tone="danger"
        :title="errors.invitation"
        data-test="invitation-error"
      />

      <RoleCards :counts="roleCounts" />
      <MembersTable
        :members="members"
        :can-manage="canManage"
        :current-user-id="currentUserId"
        @role="changeRole"
        @remove="removing = $event"
        @leave="leaving = true"
      />
      <PendingInvites
        v-if="canManage && invitations.length"
        :invitations="invitations"
        @resend="resend"
        @cancel="cancel"
      />
    </div>

    <InviteDialog
      v-model:open="inviting"
      :action="`${base}/invitations`"
      :projects="[{ id: project.id, name: project.name }]"
      :can-choose-org-role="false"
      :fixed-project-id="project.id"
      :errors="errors"
    />

    <ConfirmDialog
      v-model:open="removeOpen"
      title="Remove member"
      :object-name="removing?.name ?? ''"
      description="They lose access to this project."
      confirm-label="Remove"
      destructive
      @confirm="confirmRemove"
    />
    <ConfirmDialog
      v-model:open="leaving"
      title="Leave project"
      :object-name="project.name"
      description="You will lose access until someone adds you again."
      confirm-label="Leave"
      destructive
      @confirm="leave"
    />
  </PortalLayout>
</template>
