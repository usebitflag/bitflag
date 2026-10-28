<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Button from '../../components/ui/Button.vue';
import { useCountdown } from '../../features/auth/useCountdown';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps<{ email: string; resendSeconds: number }>();

const { remaining, restart } = useCountdown(props.resendSeconds);

function resend(): void {
  router.post('/forgot-password/resend', {}, { onSuccess: restart });
}
</script>

<template>
  <Head title="Check your email" />
  <AuthLayout title="Check your email">
    <div class="flex flex-col gap-4">
      <p class="text-sm" data-test="reset-sent-message">
        If an account exists for {{ email }}, a reset link is on its way. The link works for 60
        minutes.
      </p>
      <Button :disabled="remaining > 0" data-test="reset-resend" @click="resend">
        {{ remaining > 0 ? `Send again in ${remaining}s` : 'Send again' }}
      </Button>
      <p class="text-muted text-center text-sm">
        <Link href="/signin" class="text-text underline">Back to sign in</Link>
      </p>
    </div>
  </AuthLayout>
</template>
