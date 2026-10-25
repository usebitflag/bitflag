<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import Button from '../../components/ui/Button.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps<{ organization: { name: string; slug: string }; steps: string[] }>();

function finish(): void {
  router.post(`/onboarding/${props.organization.slug}/ready`);
}
</script>

<template>
  <Head title="You're ready" />
  <AuthLayout title="You're ready" :subtitle="`${organization.name} is set up.`">
    <div class="flex flex-col gap-4">
      <p class="text-sm">Four steps to your first flag in production:</p>
      <ol class="flex list-decimal flex-col gap-1 pl-5 text-sm" data-test="ready-steps">
        <li v-for="step in steps" :key="step">{{ step }}</li>
      </ol>
      <Button variant="primary" block data-test="ready-go" @click="finish">Go to Overview</Button>
    </div>
  </AuthLayout>
</template>
