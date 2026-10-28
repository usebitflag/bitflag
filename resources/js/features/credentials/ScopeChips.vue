<script setup lang="ts">
import Tag from '../../components/ui/Tag.vue';

defineProps<{ scopes: string[] }>();
</script>

<template>
  <span class="inline-flex flex-wrap gap-1" data-test="scope-chips">
    <Tag v-for="scope in scopes" :key="scope" variant="outline" mono>{{ scope }}</Tag>
  </span>
</template>
