<script setup lang="ts">
import { PhUserMinus } from '@phosphor-icons/vue';
import Button from '../../components/ui/Button.vue';
import IconButton from '../../components/ui/IconButton.vue';
import Select from '../../components/ui/Select.vue';
import PersonCell from './PersonCell.vue';
import type { ProjectRoleName } from './RoleCards.vue';

export interface ProjectMember {
  membershipId: string;
  userId: string;
  name: string;
  email: string;
  role: ProjectRoleName;
  version: number;
  twoFactor: boolean;
  lastActiveAt: number | null;
}

defineProps<{ members: ProjectMember[]; canManage: boolean; currentUserId: string }>();
const emit = defineEmits<{
  role: [member: ProjectMember, role: ProjectRoleName];
  remove: [member: ProjectMember];
  leave: [];
}>();

const roles: Array<{ value: ProjectRoleName; label: string }> = [
  { value: 'owner', label: 'Owner' },
  { value: 'publisher', label: 'Publisher' },
  { value: 'developer', label: 'Developer' },
  { value: 'viewer', label: 'Viewer' },
];

const formatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

function lastActive(seconds: number | null): string {
  return seconds === null ? 'Never' : formatter.format(new Date(seconds * 1000));
}
</script>

<template>
  <div class="bg-surface border-divider overflow-hidden rounded-lg border">
    <table class="w-full border-collapse text-[13px]">
      <caption class="sr-only">
        Project members
      </caption>
      <thead>
        <tr class="text-text/55 border-divider border-b text-left">
          <th scope="col" class="py-1.5 pr-4 pl-4 font-medium">Member</th>
          <th scope="col" class="px-4 py-1.5 font-medium">Role</th>
          <th scope="col" class="px-4 py-1.5 font-medium">Two-factor</th>
          <th scope="col" class="px-4 py-1.5 font-medium">Last active</th>
          <th scope="col" class="px-4 py-1.5"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="member in members"
          :key="member.membershipId"
          class="border-divider hover:bg-text/[0.025] border-b last:border-0"
          data-test="member-row"
        >
          <td class="py-1.5 pr-4 pl-4">
            <PersonCell
              :name="member.name"
              :email="member.email"
              :you="member.userId === currentUserId"
            />
          </td>
          <td class="px-4 py-1.5">
            <Select
              class="w-[150px]"
              :model-value="member.role"
              :options="roles"
              :disabled="!canManage || member.userId === currentUserId"
              :aria-label="`Role of ${member.name}`"
              data-test="member-role"
              @update:model-value="emit('role', member, $event as ProjectRoleName)"
            />
          </td>
          <td
            :class="['px-4 py-1.5', member.twoFactor ? 'text-text/75' : 'text-primary-ink']"
            data-test="member-two-factor"
          >
            {{ member.twoFactor ? 'On' : 'Off' }}
          </td>
          <td class="text-text/65 px-4 py-1.5" data-test="member-last-active">
            {{ lastActive(member.lastActiveAt) }}
          </td>
          <td class="px-4 py-1.5 text-right">
            <Button
              v-if="member.userId === currentUserId"
              size="sm"
              variant="ghost"
              data-test="member-leave"
              @click="emit('leave')"
              >Leave project</Button
            >
            <IconButton
              v-else-if="canManage"
              label="Remove"
              class="!text-text !h-7 !w-7"
              data-test="member-remove"
              @click="emit('remove', member)"
            >
              <PhUserMinus :size="16" aria-hidden="true" />
            </IconButton>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
