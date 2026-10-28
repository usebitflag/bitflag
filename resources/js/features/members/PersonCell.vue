<script setup lang="ts">
import Avatar from '../../components/ui/Avatar.vue';
import Tag from '../../components/ui/Tag.vue';

defineProps<{ name: string; email: string; you?: boolean }>();
</script>

<template>
  <span class="flex items-center gap-2.5">
    <Avatar :name="name" :size="28" />
    <span class="flex min-w-0 flex-col gap-0.5">
      <span class="text-[13px] leading-5">
        {{ name }}
        <Tag v-if="you" class="!h-auto !px-1.5 !py-px !text-[10.5px]" data-test="you-tag">You</Tag>
      </span>
      <span class="text-text/55 text-xs leading-[19px]">{{ email }}</span>
    </span>
  </span>
</template>
