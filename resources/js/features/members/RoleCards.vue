<script setup lang="ts">
export type ProjectRoleName = 'owner' | 'publisher' | 'developer' | 'viewer';

defineProps<{ counts: Record<ProjectRoleName, number> }>();

const roles: Array<{ key: ProjectRoleName; label: string; description: string }> = [
  {
    key: 'owner',
    label: 'Owner',
    description: 'Everything, including members and deleting the project.',
  },
  {
    key: 'publisher',
    label: 'Publisher',
    description: 'Edit and publish anywhere, and approve reviews.',
  },
  {
    key: 'developer',
    label: 'Developer',
    description: 'Edit drafts and publish to unprotected environments.',
  },
  { key: 'viewer', label: 'Viewer', description: 'Read flags, results and the audit log.' },
];

function people(count: number): string {
  return `${count} ${count === 1 ? 'person' : 'people'}`;
}
</script>

<template>
  <ul class="grid grid-cols-4 items-start gap-2.5" aria-label="Roles" data-test="role-cards">
    <li
      v-for="role in roles"
      :key="role.key"
      class="ring-divider flex flex-col gap-1 rounded-md p-3 ring-1 ring-inset"
      :data-test="`role-card-${role.key}`"
    >
      <span class="flex text-[13.5px]">
        {{ role.label }}
        <span class="text-text/55 ml-auto text-[11.5px]" :data-test="`role-count-${role.key}`">{{
          people(counts[role.key])
        }}</span>
      </span>
      <span class="text-text/62 text-[12.5px]">{{ role.description }}</span>
    </li>
  </ul>
</template>
