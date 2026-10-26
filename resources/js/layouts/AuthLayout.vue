<script setup lang="ts">
import BrandMark from '../features/shell/BrandMark.vue';

withDefaults(
  defineProps<{
    title: string;
    subtitle?: string;
    /** Width of the content column in pixels. */
    width?: number;
  }>(),
  { width: 400 },
);
</script>

<template>
  <div
    class="text-text flex min-h-screen flex-col px-10 py-7"
    style="
      background:
        radial-gradient(
          900px 560px at 95% -20%,
          color-mix(in srgb, var(--bf-primary-soft) 80%, transparent),
          transparent 65%
        ),
        var(--bf-bg);
    "
    data-test="auth-layout"
  >
    <header class="flex items-center gap-2.5 text-lg font-medium">
      <BrandMark :size="22" />
      BitFlag
      <div class="ml-auto"><slot name="aside" /></div>
    </header>

    <main
      class="m-auto flex w-full flex-col gap-4 py-8"
      :style="{ maxWidth: `${width}px` }"
      data-test="auth-main"
    >
      <slot name="before" />
      <div v-if="title">
        <h1 class="font-heading text-[28px]" data-test="auth-title">{{ title }}</h1>
        <p v-if="subtitle || $slots.subtitle" class="text-text/65 mt-1.5 text-[14px]">
          <slot name="subtitle">{{ subtitle }}</slot>
        </p>
      </div>
      <slot />
    </main>
  </div>
</template>
