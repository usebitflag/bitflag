<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { NavigationItem } from '../../types/shared';
import { iconFor } from './icons';

const props = withDefaults(defineProps<{ items: NavigationItem[]; expanded?: boolean }>(), {
  expanded: false,
});

const groups = computed(() => {
  const byGroup = new Map<string, NavigationItem[]>();
  for (const item of props.items)
    byGroup.set(item.group, [...(byGroup.get(item.group) ?? []), item]);
  // Groups with no entries never appear, so the sidebar shows only pages that exist.
  return [...byGroup.entries()];
});

const labelClass = computed(() => (props.expanded ? '' : 'hidden xl:inline'));
</script>

<template>
  <nav aria-label="Project" class="flex flex-col gap-4 px-2 py-2" data-test="sidebar-nav">
    <section
      v-for="[group, entries] in groups"
      :key="group"
      :aria-label="group"
      data-test="nav-group"
    >
      <h2 :class="['text-muted px-2 pb-1 text-xs font-medium', labelClass]">{{ group }}</h2>
      <ul class="flex flex-col gap-0.5">
        <li v-for="item in entries" :key="item.key">
          <Link
            :href="item.href"
            :aria-current="item.active ? 'page' : undefined"
            :title="item.label"
            :class="[
              'flex h-8 items-center gap-2.5 rounded-md px-2 text-md',
              item.active
                ? 'bg-hover text-text font-medium shadow-[inset_2px_0_0_var(--bf-primary)]'
                : 'text-text/80 hover:bg-hover',
            ]"
            data-test="nav-item"
          >
            <component :is="iconFor(item.icon)" :size="16" aria-hidden="true" />
            <span :class="['flex-1', labelClass]">{{ item.label }}</span>
            <span
              v-if="item.count !== null"
              :class="['tnum bg-hover rounded-sm px-1.5 text-xs', labelClass]"
              :aria-label="`${item.count} ${item.label.toLowerCase()}`"
              data-test="nav-count"
            >
              {{ item.count }}
            </span>
          </Link>
        </li>
      </ul>
    </section>
  </nav>
</template>
