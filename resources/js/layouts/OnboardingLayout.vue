<script setup lang="ts">
import { PhCheckCircle, PhCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';

const props = defineProps<{
  step: 'organization' | 'project' | 'team';
  title: string;
  subtitle?: string;
}>();

const steps = [
  { key: 'account', label: 'Account' },
  { key: 'organization', label: 'Organization' },
  { key: 'project', label: 'Project' },
  { key: 'team', label: 'Team' },
] as const;

const current = computed(() => steps.findIndex((step) => step.key === props.step));
</script>

<template>
  <main
    class="bg-bg text-text grid min-h-screen place-items-start justify-center p-6 pt-16"
    data-test="onboarding-layout"
  >
    <div class="w-full max-w-lg">
      <ol class="mb-8 flex items-center gap-2 text-sm" aria-label="Setup progress">
        <li
          v-for="(entry, index) in steps"
          :key="entry.key"
          :class="['flex items-center gap-1.5', index <= current ? 'text-text' : 'text-muted']"
          :aria-current="index === current ? 'step' : undefined"
          :data-test="`stepper-${entry.key}`"
        >
          <PhCheckCircle v-if="index < current" :size="16" aria-hidden="true" />
          <PhCircle v-else :size="16" aria-hidden="true" />
          <span>{{ entry.label }}</span>
          <span v-if="index < steps.length - 1" class="text-muted mx-1" aria-hidden="true">·</span>
        </li>
      </ol>
      <h1 class="font-heading text-2xl" data-test="onboarding-title">{{ title }}</h1>
      <p v-if="subtitle" class="text-muted mt-1 text-sm">{{ subtitle }}</p>
      <div class="bg-surface border-divider mt-6 rounded-lg border p-6"><slot /></div>
    </div>
  </main>
</template>
