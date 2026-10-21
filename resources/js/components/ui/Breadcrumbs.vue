<script setup lang="ts">
export interface Crumb {
  label: string;
  href?: string;
}

defineProps<{ items: Crumb[] }>();
</script>

<template>
  <nav aria-label="Breadcrumb" data-test="breadcrumbs">
    <ol class="text-muted flex items-center gap-1.5 text-sm">
      <li v-for="(item, index) in items" :key="index" class="flex items-center gap-1.5">
        <a v-if="item.href && index < items.length - 1" :href="item.href" class="hover:text-text">{{
          item.label
        }}</a>
        <span
          v-else
          :aria-current="index === items.length - 1 ? 'page' : undefined"
          :class="index === items.length - 1 ? 'text-text' : ''"
          >{{ item.label }}</span
        >
        <span v-if="index < items.length - 1" aria-hidden="true">/</span>
      </li>
    </ol>
  </nav>
</template>
