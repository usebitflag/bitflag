<script setup lang="ts">
defineProps<{ title: string; subtitle?: string }>();
</script>

<template>
  <main class="bg-bg text-text grid min-h-screen place-items-center p-6" data-test="auth-layout">
    <div class="w-full max-w-sm">
      <div class="mb-6">
        <h1 class="font-heading text-2xl" data-test="auth-title">{{ title }}</h1>
        <p v-if="subtitle" class="text-muted mt-1 text-sm">{{ subtitle }}</p>
      </div>
      <div class="bg-surface border-divider rounded-lg border p-6">
        <slot />
      </div>
    </div>
  </main>
</template>
