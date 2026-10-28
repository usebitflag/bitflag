<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export interface SettingsTab {
  key: string;
  label: string;
  href: string;
  active: boolean;
}

defineProps<{ items: SettingsTab[]; label: string }>();
</script>

<template>
  <nav
    :aria-label="label"
    class="flex gap-0.5 shadow-[inset_0_-1px_0_var(--bf-divider)]"
    data-test="settings-tabs"
  >
    <Link
      v-for="item in items"
      :key="item.key"
      :href="item.href"
      :aria-current="item.active ? 'page' : undefined"
      :class="[
        'inline-flex items-center px-3 py-[9px] text-[13.5px]',
        item.active
          ? 'text-text shadow-[inset_0_-2px_0_var(--bf-primary)]'
          : 'text-text/65 hover:text-text',
      ]"
      :data-test="`settings-tab-${item.key}`"
    >
      {{ item.label }}
    </Link>
  </nav>
</template>
