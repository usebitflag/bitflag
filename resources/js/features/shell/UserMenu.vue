<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Avatar from '../../components/ui/Avatar.vue';
import DropdownMenu from '../../components/ui/DropdownMenu.vue';
import { confirmLeave } from '../../composables/useNavigationGuard';
import type { SharedUser } from '../../types/shared';

const props = defineProps<{ user: SharedUser; organizationSettingsHref?: string }>();

const items = [
  ...(props.organizationSettingsHref
    ? [{ key: 'organization', label: 'Organization settings' }]
    : []),
  { key: 'signout', label: 'Sign out', destructive: true },
];

function select(key: string): void {
  if (!confirmLeave()) return;
  if (key === 'organization' && props.organizationSettingsHref)
    router.visit(props.organizationSettingsHref);
  if (key === 'signout') router.post('/signout');
}
</script>

<template>
  <DropdownMenu :items="items" label="Account menu" @select="select">
    <template #trigger>
      <button
        type="button"
        class="hover:bg-hover flex h-14 w-full items-center gap-2.5 px-3 text-left"
        data-test="user-menu"
      >
        <Avatar :name="user.name" :size="28" />
        <span class="hidden min-w-0 flex-1 flex-col leading-tight xl:flex">
          <span class="truncate text-md font-medium" data-test="user-name">{{ user.name }}</span>
          <span class="text-muted truncate text-xs" data-test="user-email">{{ user.email }}</span>
        </span>
      </button>
    </template>
  </DropdownMenu>
</template>
