<script setup lang="ts">
import BrandMark from '../features/shell/BrandMark.vue';

withDefaults(
  defineProps<{
    title: string;
    subtitle?: string;
    /** `start` puts the brand at the left with room for a note or the setup steps on the right; `center` centers it. */
    variant?: 'start' | 'center';
    /** Width of the content column in pixels. */
    width?: number;
  }>(),
  { variant: 'center', width: 380 },
);
</script>

<template>
  <div
    :class="[
      'text-text flex min-h-screen flex-col',
      variant === 'start' ? 'px-10 py-7' : 'items-center px-10 py-8',
    ]"
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
    <header class="flex items-center gap-2.5 self-stretch text-lg font-medium">
      <div :class="['flex items-center gap-2.5', variant === 'center' ? 'mx-auto' : '']">
        <BrandMark :size="22" />
        BitFlag
      </div>
      <div v-if="variant === 'start'" class="ml-auto"><slot name="aside" /></div>
    </header>

    <main
      class="m-auto flex w-full flex-col gap-4"
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

    <footer
      v-if="variant === 'center'"
      class="text-text/50 flex min-h-[18px] gap-4 self-stretch text-xs"
    >
      <slot name="footer" />
    </footer>
  </div>
</template>
