<script setup lang="ts">
import { PhClockCountdown } from '@phosphor-icons/vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps<{
  reason: 'expired' | 'revoked' | 'used' | 'unknown';
  organizationName: string | null;
  inviterName: string | null;
}>();

const title = computed(() => {
  if (props.reason === 'expired') return 'This invitation has expired';
  if (props.reason === 'revoked') return 'This invitation was withdrawn';
  if (props.reason === 'used') return 'This invitation was already used';
  return "This invitation can't be used";
});

const message = computed(() => {
  if (props.reason === 'used') return 'This invitation was already used.';

  if (props.reason === 'expired' || props.reason === 'revoked') {
    const who = props.inviterName ?? 'the person who invited you';
    const where = props.organizationName ?? 'the organization';
    return props.reason === 'expired'
      ? `Invitations to ${where} last 7 days. Ask ${who} or another owner to send a new one.`
      : `This invitation was withdrawn. Ask ${who} or another owner to send a new one.`;
  }

  return 'This invitation link is not valid. Check the link in your email, or ask the person who invited you to send a new one.';
});
</script>

<template>
  <Head title="Invitation unavailable" />
  <AuthLayout :title="title">
    <template #before>
      <PhClockCountdown :size="28" class="text-primary" aria-hidden="true" />
    </template>
    <template #subtitle>
      <span data-test="invitation-unavailable-message">{{ message }}</span>
    </template>
    <div class="flex gap-2">
      <Button href="/signin" data-test="invitation-unavailable-signin">Go to sign in</Button>
    </div>
  </AuthLayout>
</template>
