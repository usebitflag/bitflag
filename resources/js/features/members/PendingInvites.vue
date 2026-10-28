<script setup lang="ts">
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';

export interface ProjectInvitation {
  id: string;
  email: string;
  role: string;
  status: 'queued' | 'pending' | 'expired';
  inviter: string;
  expiresAt: number | null;
}

defineProps<{ invitations: ProjectInvitation[] }>();
const emit = defineEmits<{
  resend: [invitation: ProjectInvitation];
  cancel: [invitation: ProjectInvitation];
}>();

const formatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

function status(invitation: ProjectInvitation): string {
  if (invitation.status === 'queued') return 'Waiting for the inviter to confirm their email';
  if (invitation.status === 'expired') return 'Expired';
  return invitation.expiresAt === null
    ? 'Expires in 7 days'
    : `Expires ${formatter.format(new Date(invitation.expiresAt * 1000))}`;
}
</script>

<template>
  <Card
    title="Pending invites"
    meta="Links expire after 7 days."
    aria-labelledby="pending-heading"
    data-test="pending-invites"
  >
    <ul class="divide-divider divide-y" aria-label="Pending invitations">
      <li
        v-for="invitation in invitations"
        :key="invitation.id"
        class="flex items-center justify-between gap-4 py-2 first:pt-0 last:pb-0"
        data-test="invitation-row"
      >
        <div>
          <p class="text-[13px] leading-5">{{ invitation.email }}</p>
          <p class="text-text/55 text-xs leading-[19px]">
            {{ invitation.role }} · invited by {{ invitation.inviter }} ·
            <span data-test="invitation-status">{{ status(invitation) }}</span>
          </p>
        </div>
        <div class="flex gap-2">
          <Button
            v-if="invitation.status === 'pending'"
            size="sm"
            data-test="invitation-resend"
            @click="emit('resend', invitation)"
            >Resend</Button
          >
          <Button
            size="sm"
            variant="ghost"
            data-test="invitation-cancel"
            @click="emit('cancel', invitation)"
            >Cancel invite</Button
          >
        </div>
      </li>
    </ul>
  </Card>
</template>
