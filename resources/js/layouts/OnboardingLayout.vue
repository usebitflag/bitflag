<script setup lang="ts">
import { PhCheck } from '@phosphor-icons/vue';
import { computed } from 'vue';
import AuthLayout from './AuthLayout.vue';

const props = withDefaults(
  defineProps<{
    step: 'organization' | 'project' | 'team' | 'ready';
    title: string;
    subtitle?: string;
    width?: number;
  }>(),
  { width: 400 },
);

const steps = [
  { key: 'account', label: 'Account' },
  { key: 'organization', label: 'Organization' },
  { key: 'project', label: 'Project' },
  { key: 'team', label: 'Team' },
] as const;

const current = computed(() => steps.findIndex((step) => step.key === props.step));
</script>

<template>
  <AuthLayout variant="start" :title="title" :subtitle="subtitle" :width="width">
    <template #aside>
      <ol
        v-if="step !== 'ready'"
        class="flex gap-1.5 text-xs"
        aria-label="Setup progress"
        data-test="onboarding-layout"
      >
        <li
          v-for="(entry, index) in steps"
          :key="entry.key"
          :class="[
            'rounded-full px-[9px] py-[3px]',
            index === current ? 'text-primary ring-primary ring-1 ring-inset' : '',
            index > current ? 'text-text/50' : '',
          ]"
          :aria-current="index === current ? 'step' : undefined"
          :data-test="`stepper-${entry.key}`"
        >
          <PhCheck v-if="index < current" :size="11" class="mr-0.5 inline" aria-hidden="true" />{{
            entry.label
          }}
        </li>
      </ol>
    </template>
    <template v-if="$slots.before" #before><slot name="before" /></template>
    <slot />
  </AuthLayout>
</template>
