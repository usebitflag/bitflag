<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../../components/ui/Button.vue';
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
  <OrgSettingsLayout title="Members">
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
      <p class="text-muted text-sm" data-test="members-summary">
        <template v-if="canManage"
          >{{ members.length }} {{ members.length === 1 ? 'person' : 'people' }}</template
        >
        <template v-else>Your membership. Org Owners manage everyone else.</template>
      </p>
      <Button v-if="canManage" variant="primary" data-test="members-invite" @click="inviting = true"
        >Invite people</Button
      >
    </div>

    <div class="border-divider overflow-x-auto rounded-lg border">
      <table class="w-full border-collapse text-sm">
        <caption class="sr-only">
          Members of the organization
        </caption>
        <thead>
          <tr class="border-divider text-muted border-b text-left">
            <th scope="col" class="px-4 py-2">Person</th>
            <th scope="col" class="px-4 py-2">Organization role</th>
            <th scope="col" class="px-4 py-2">Projects</th>
            <th scope="col" class="px-4 py-2">Two-factor</th>
            <th scope="col" class="px-4 py-2">Last active</th>
            <th v-if="canManage" scope="col" class="px-4 py-2">
              <span class="sr-only">Actions</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="member in members"
            :key="member.membershipId"
            class="border-divider border-b last:border-0"
            data-test="member-row"
          >
            <td class="px-4 py-2">
              <div class="font-medium">
                {{ member.name
                }}<span v-if="member.userId === currentUserId" class="text-muted font-normal">
                  · You</span
                >
              </div>
              <div class="text-muted text-xs">{{ member.email }}</div>
            </td>
            <td class="px-4 py-2">
              <select
                v-if="canManage"
                :value="member.orgRole"
                :disabled="member.userId === currentUserId"
                class="border-control-border bg-surface h-8 rounded-md border px-2"
                :aria-label="`Organization role of ${member.name}`"
                data-test="member-role"
                @change="changeRole(member, ($event.target as HTMLSelectElement).value)"
              >
                <option value="owner">Org Owner</option>
                <option value="member">Member</option>
              </select>
              <span v-else>{{ member.orgRole === 'owner' ? 'Org Owner' : 'Member' }}</span>
            </td>
            <td class="px-4 py-2" data-test="member-projects">
              <span v-if="member.projects.length === 0" class="text-muted">None</span>
              <span v-else>{{
                member.projects.map((project) => `${project.name} (${project.role})`).join(', ')
              }}</span>
            </td>
            <td class="px-4 py-2" data-test="member-two-factor">
              {{ member.twoFactor ? 'On' : 'Off' }}
            </td>
            <td class="px-4 py-2" data-test="member-last-active">
              {{ lastActive(member.lastActiveAt) }}
            </td>
            <td v-if="canManage" class="px-4 py-2 text-right">
              <Button
                v-if="member.userId !== currentUserId"
                size="sm"
                variant="ghost"
                data-test="member-remove"
                @click="removing = member"
              >
                Remove
              </Button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <section
      v-if="canManage && invitations.length"
      class="flex flex-col gap-2"
      aria-labelledby="pending-heading"
      data-test="pending-invitations"
    >
      <h2 id="pending-heading" class="font-heading text-lg">Pending invitations</h2>
      <ul class="border-divider divide-divider divide-y rounded-md border">
        <li
          v-for="invitation in invitations"
          :key="invitation.id"
          class="flex items-center justify-between gap-4 p-3"
          data-test="invitation-row"
        >
          <div>
            <p class="text-sm font-medium">{{ invitation.email }}</p>
            <p class="text-muted text-xs">
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
      <p class="text-muted text-xs">Links expire after 7 days.</p>
    </section>

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
