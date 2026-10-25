<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { PhCheckCircle, PhUserMinus, PhUserPlus, PhWarning } from '@phosphor-icons/vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
import IconButton from '../../components/ui/IconButton.vue';
import PersonCell from '../../features/members/PersonCell.vue';
import Select from '../../components/ui/Select.vue';
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import InviteDialog, { type InvitableProject } from '../../features/members/InviteDialog.vue';
import OrgSettingsLayout from '../../layouts/OrgSettingsLayout.vue';
import type { SharedProps } from '../../types/shared';

interface Member {
  membershipId: string;
  userId: string;
  name: string;
  email: string;
  orgRole: 'owner' | 'member';
  twoFactor: boolean;
  lastActiveAt: number | null;
  projects: Array<{ name: string; role: string }>;
}

interface OpenInvitation {
  id: string;
  email: string;
  orgRole: 'owner' | 'member';
  status: 'queued' | 'pending' | 'expired';
  inviter: string;
  expiresAt: number | null;
  projects: Array<{ name: string; role: string }>;
}

defineProps<{
  members: Member[];
  invitations: OpenInvitation[];
  projects: InvitableProject[];
  canManage: boolean;
  currentUserId: string;
}>();

const page = usePage<SharedProps>();
const slug = computed(() => page.props.scope.organization?.slug ?? '');
const base = computed(() => `/${slug.value}/settings/members`);
const inviting = ref(false);
const removing = ref<Member | null>(null);
const removeOpen = computed({
  get: () => removing.value !== null,
  set: (value) => {
    if (!value) removing.value = null;
  },
});

const errors = computed<Record<string, string | undefined>>(() => page.props.errors ?? {});
const formatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });
const roleOptions = [
  { value: 'owner', label: 'Org Owner' },
  { value: 'member', label: 'Member' },
];

function lastActive(seconds: number | null): string {
  return seconds === null ? 'Never' : formatter.format(new Date(seconds * 1000));
}

function changeRole(member: Member, role: string): void {
  router.patch(`${base.value}/${member.membershipId}`, { role }, { preserveScroll: true });
}

function confirmRemove(): void {
  const member = removing.value;
  if (member === null) return;
  router.delete(`${base.value}/${member.membershipId}`, {
    preserveScroll: true,
    onFinish: () => (removing.value = null),
  });
}

function resend(invitation: OpenInvitation): void {
  router.post(`${base.value}/invitations/${invitation.id}/resend`, {}, { preserveScroll: true });
}

function revoke(invitation: OpenInvitation): void {
  router.delete(`${base.value}/invitations/${invitation.id}`, { preserveScroll: true });
}
</script>

<template>
  <Head title="Members" />
  <OrgSettingsLayout
    title="Members"
    :description="`Everyone in ${page.props.scope.organization?.name ?? 'this organization'}. Organization roles cover billing and security; what someone can do in a project is set in that project's Members tab.`"
  >
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

    <div class="flex items-center justify-between gap-4">
      <p class="text-text/65 text-[13px]" data-test="members-summary">
        <template v-if="canManage"
          >{{ members.length }} {{ members.length === 1 ? 'person' : 'people' }}</template
        >
        <template v-else>Your membership. Org Owners manage everyone else.</template>
      </p>
      <Button
        v-if="canManage"
        variant="primary"
        data-test="members-invite"
        @click="inviting = true"
      >
        <template #icon><PhUserPlus :size="16" aria-hidden="true" /></template>
        Invite people
      </Button>
    </div>

    <ul class="grid grid-cols-3 items-start gap-2.5" aria-label="Organization roles">
      <li class="ring-divider flex flex-col gap-1 rounded-md p-3 ring-1 ring-inset">
        <span class="text-[13.5px]">Org Owner</span>
        <span class="text-text/64 text-[12.5px]"
          >Billing, members, security and every project. At least one is required.</span
        >
      </li>
      <li class="ring-divider flex flex-col gap-1 rounded-md p-3 ring-1 ring-inset">
        <span class="text-[13.5px]">Member</span>
        <span class="text-text/64 text-[12.5px]"
          >Access only to the projects they're added to, with that project's role.</span
        >
      </li>
    </ul>

    <div class="bg-surface border-divider overflow-hidden rounded-lg border">
      <table class="w-full border-collapse text-[13px]">
        <caption class="sr-only">
          Members of the organization
        </caption>
        <thead>
          <tr class="text-text/55 border-divider border-b text-left">
            <th scope="col" class="py-1.5 pr-4 pl-[18px] font-medium">Person</th>
            <th scope="col" class="px-4 py-1.5 font-medium">Organization role</th>
            <th scope="col" class="px-4 py-1.5 font-medium">Project access</th>
            <th scope="col" class="px-4 py-1.5 font-medium">Two-factor</th>
            <th scope="col" class="px-4 py-1.5 font-medium">Last active</th>
            <th v-if="canManage" scope="col" class="px-4 py-1.5">
              <span class="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="member in members"
            :key="member.membershipId"
            class="border-divider hover:bg-text/[0.025] border-b last:border-0"
            data-test="member-row"
          >
            <td class="py-1.5 pr-4 pl-[18px]">
              <PersonCell
                :name="member.name"
                :email="member.email"
                :you="member.userId === currentUserId"
              />
            </td>
            <td class="px-4 py-1.5">
              <Select
                v-if="canManage"
                class="w-[150px]"
                :model-value="member.orgRole"
                :options="roleOptions"
                :disabled="member.userId === currentUserId"
                :aria-label="`Organization role of ${member.name}`"
                data-test="member-role"
                @update:model-value="changeRole(member, $event)"
              />
              <span v-else>{{ member.orgRole === 'owner' ? 'Org Owner' : 'Member' }}</span>
            </td>
            <td class="text-text/75 px-4 py-1.5 text-[12.5px]" data-test="member-projects">
              <span v-if="member.projects.length === 0">No project access</span>
              <span v-else>{{
                member.projects.map((project) => `${project.name} (${project.role})`).join(', ')
              }}</span>
            </td>
            <td class="px-4 py-1.5" data-test="member-two-factor">
              <span class="inline-flex items-center gap-1.5">
                <PhCheckCircle v-if="member.twoFactor" :size="14" aria-hidden="true" />
                <PhWarning v-else :size="14" class="text-primary-ink" aria-hidden="true" />
                <span :class="member.twoFactor ? '' : 'text-primary-ink'">{{
                  member.twoFactor ? 'On' : 'Off'
                }}</span>
              </span>
            </td>
            <td class="text-text/65 px-4 py-1.5" data-test="member-last-active">
              {{ lastActive(member.lastActiveAt) }}
            </td>
            <td v-if="canManage" class="px-4 py-1.5 text-right">
              <IconButton
                v-if="member.userId !== currentUserId"
                label="Remove"
                class="!text-text !h-7 !w-7"
                data-test="member-remove"
                @click="removing = member"
              >
                <PhUserMinus :size="16" aria-hidden="true" />
              </IconButton>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Card
      v-if="canManage && invitations.length"
      title="Pending invites"
      meta="Links expire after 7 days."
      data-test="pending-invitations"
    >
      <ul class="divide-divider divide-y" aria-label="Pending invitations">
        <li
          v-for="invitation in invitations"
          :key="invitation.id"
          class="flex items-center justify-between gap-4 py-2 first:pt-0 last:pb-0"
          data-test="invitation-row"
        >
          <div>
            <p class="text-[13px] leading-5">{{ invitation.email }}</p>
            <p class="text-text/55 text-xs leading-[19px]">
              {{ invitation.orgRole === 'owner' ? 'Org Owner' : 'Member' }} · invited by
              {{ invitation.inviter }} ·
              <span data-test="invitation-status">{{
                invitation.status === 'queued'
                  ? 'Waiting for the inviter to confirm their email'
                  : invitation.status === 'expired'
                    ? 'Expired'
                    : `Expires ${invitation.expiresAt ? formatter.format(new Date(invitation.expiresAt * 1000)) : 'in 7 days'}`
              }}</span>
            </p>
          </div>
          <div class="flex gap-2">
            <Button
              v-if="invitation.status === 'pending'"
              size="sm"
              data-test="invitation-resend"
              @click="resend(invitation)"
              >Resend</Button
            >
            <Button
              size="sm"
              variant="ghost"
              data-test="invitation-cancel"
              @click="revoke(invitation)"
              >Cancel invite</Button
            >
          </div>
        </li>
      </ul>
    </Card>

    <InlineNotice
      plain
      title="Seats are unlimited on every plan. Inviting people never changes your bill."
    />

    <InviteDialog
      v-model:open="inviting"
      :action="`${base}/invitations`"
      :projects="projects"
      can-choose-org-role
      :errors="errors"
    />

    <ConfirmDialog
      v-model:open="removeOpen"
      title="Remove member"
      :object-name="removing?.name ?? ''"
      description="They lose access to the organization and every project in it."
      confirm-label="Remove"
      destructive
      @confirm="confirmRemove"
    />
  </OrgSettingsLayout>
</template>
