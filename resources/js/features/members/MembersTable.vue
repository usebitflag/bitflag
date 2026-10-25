<script setup lang="ts">
import Button from '../../components/ui/Button.vue';
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
  <div class="border-divider overflow-x-auto rounded-lg border">
    <table class="w-full border-collapse text-sm">
      <caption class="sr-only">
        Project members
      </caption>
      <thead>
        <tr class="border-divider text-muted border-b text-left">
          <th scope="col" class="px-4 py-2">Person</th>
          <th scope="col" class="px-4 py-2">Role</th>
          <th scope="col" class="px-4 py-2">Two-factor</th>
          <th scope="col" class="px-4 py-2">Last active</th>
          <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
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
              :value="member.role"
              :disabled="!canManage || member.userId === currentUserId"
              class="border-control-border bg-surface h-8 rounded-md border px-2 disabled:opacity-70"
              :aria-label="`Role of ${member.name}`"
              data-test="member-role"
              @change="
                emit('role', member, ($event.target as HTMLSelectElement).value as ProjectRoleName)
              "
            >
              <option v-for="role in roles" :key="role.value" :value="role.value">
                {{ role.label }}
              </option>
            </select>
          </td>
          <td class="px-4 py-2" data-test="member-two-factor">
            {{ member.twoFactor ? 'On' : 'Off' }}
          </td>
          <td class="px-4 py-2" data-test="member-last-active">
            {{ lastActive(member.lastActiveAt) }}
          </td>
          <td class="px-4 py-2 text-right">
            <Button
              v-if="member.userId === currentUserId"
              size="sm"
              variant="ghost"
              data-test="member-leave"
              @click="emit('leave')"
              >Leave project</Button
            >
            <Button
              v-else-if="canManage"
              size="sm"
              variant="ghost"
              data-test="member-remove"
              @click="emit('remove', member)"
              >Remove</Button
            >
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
