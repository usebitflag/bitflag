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
    description: 'Edit drafts and publish environments that are not protected.',
  },
  { key: 'viewer', label: 'Viewer', description: 'Look, but not change anything.' },
];
</script>

<template>
  <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Roles" data-test="role-cards">
    <li
      v-for="role in roles"
      :key="role.key"
      class="border-divider rounded-lg border p-3"
      :data-test="`role-card-${role.key}`"
    >
      <div class="flex items-baseline justify-between">
        <span class="text-sm font-medium">{{ role.label }}</span>
        <span class="text-muted text-sm" :data-test="`role-count-${role.key}`">{{
          counts[role.key]
        }}</span>
      </div>
      <p class="text-muted mt-1 text-xs">{{ role.description }}</p>
    </li>
  </ul>
</template>
