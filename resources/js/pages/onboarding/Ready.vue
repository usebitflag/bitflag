<script setup lang="ts">
import { PhArrowRight, PhCheckCircle } from '@phosphor-icons/vue';
import { Head, router } from '@inertiajs/vue3';
import Button from '../../components/ui/Button.vue';
import IconTile from '../../components/ui/IconTile.vue';
import OnboardingLayout from '../../layouts/OnboardingLayout.vue';

const props = defineProps<{
  organization: { name: string; slug: string };
  projectName: string | null;
  steps: string[];
}>();

function finish(): void {
  router.post(`/onboarding/${props.organization.slug}/ready`);
}
</script>

<template>
  <Head title="You're ready" />
  <OnboardingLayout
    step="ready"
    :title="`${projectName ?? organization.name} is ready`"
    subtitle="Next, get one flag working end to end."
    :width="440"
  >
    <template #before>
      <IconTile><PhCheckCircle :size="22" aria-hidden="true" /></IconTile>
    </template>
    <ol
      class="bg-surface border-divider flex flex-col gap-2.5 rounded-lg border p-[18px]"
      data-test="ready-steps"
    >
      <li v-for="(step, index) in steps" :key="step" class="flex items-center gap-2.5 text-md">
        <span
          class="text-primary ring-selection grid h-5 w-5 place-items-center rounded-full text-[11px] ring-1 ring-inset"
          aria-hidden="true"
          >{{ index + 1 }}</span
        >
        {{ step }}
      </li>
    </ol>
    <Button variant="primary" block data-test="ready-go" @click="finish">
      <template #icon><PhArrowRight :size="14" aria-hidden="true" /></template>
      Go to Overview
    </Button>
  </OnboardingLayout>
</template>
