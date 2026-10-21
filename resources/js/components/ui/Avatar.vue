<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ name: string; size?: number }>(), { size: 28 });

const initials = computed(() =>
  props.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join(''),
);
</script>

<template>
  <span
    class="bg-primary-soft text-primary-ink inline-grid shrink-0 place-items-center rounded-full text-xs font-semibold"
    :style="{ width: `${size}px`, height: `${size}px` }"
    role="img"
    :aria-label="name"
    data-test="avatar"
  >
    {{ initials }}
  </span>
</template>
