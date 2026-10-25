<script setup lang="ts">
import { PhEnvelopeSimple } from '@phosphor-icons/vue';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import { useCountdown } from '../../features/auth/useCountdown';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps<{ email: string; resendSeconds: number }>();

const { remaining, restart } = useCountdown(props.resendSeconds);
const clock = computed(
  () => `${Math.floor(remaining.value / 60)}:${String(remaining.value % 60).padStart(2, '0')}`,
);

function resend(): void {
  router.post('/forgot-password/resend', {}, { onSuccess: restart });
}
</script>

<template>
  <Head title="Check your email" />
  <AuthLayout title="Check your email">
    <template #before>
      <PhEnvelopeSimple :size="28" class="text-primary" aria-hidden="true" />
    </template>
    <template #subtitle>
      <span data-test="reset-sent-message">
        If an account exists for <span class="text-text">{{ email }}</span
        >, a reset link is on its way. The link works for 60 minutes.
      </span>
    </template>
    <div class="flex gap-2">
      <Button href="/signin">Back to sign in</Button>
      <Button variant="ghost" :disabled="remaining > 0" data-test="reset-resend" @click="resend">
        {{ remaining > 0 ? `Resend in ${clock}` : 'Resend' }}
      </Button>
    </div>
  </AuthLayout>
</template>
