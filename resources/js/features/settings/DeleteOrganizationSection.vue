<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Banner from '../../components/ui/Banner.vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
import Dialog from '../../components/ui/Dialog.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import TypedConfirmField from './TypedConfirmField.vue';

const props = defineProps<{
  slug: string;
  name: string;
  status: string;
  deletionDueAt: number | null;
  paidSubscription: boolean;
}>();

const page = usePage<{ errors?: Record<string, string | undefined> }>();
const errors = computed(() => page.props.errors ?? {});
const open = ref(false);
const typed = ref('');
const scheduled = computed(() => props.status === 'deletion_scheduled');
const dueDate = computed(() =>
  props.deletionDueAt === null
    ? ''
    : new Date(props.deletionDueAt * 1000).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
      }),
);

function schedule(): void {
  router.post(
    `/${props.slug}/settings/general/delete`,
    { confirm: typed.value },
    { onSuccess: () => (open.value = false) },
  );
}
function restore(): void {
  router.post(`/${props.slug}/settings/general/restore`, {}, { preserveScroll: true });
}
</script>

<template>
  <Card title="Delete organization" class="max-w-[820px]" data-test="delete-organization">
    <Banner
      v-if="scheduled"
      tone="attention"
      label="Scheduled for deletion."
      data-test="organization-scheduled"
    >
      {{ name }} and its projects will be deleted on {{ dueDate }}. Until then SDKs keep working.
    </Banner>
    <p v-else class="text-text/70 mb-3 text-sm">
      The organization and all its projects are deleted after a grace period. Until then everything
      is read-only and SDKs keep working.
    </p>
    <InlineNotice
      v-if="errors.organization"
      tone="danger"
      :title="errors.organization"
      class="mb-3"
    />
    <p
      v-if="paidSubscription && !scheduled"
      class="text-text/62 mb-3 text-sm"
      data-test="paid-blocker"
    >
      Cancel the paid subscription first.
    </p>
    <div class="mt-3">
      <Button v-if="scheduled" data-test="organization-restore" @click="restore"
        >Restore organization</Button
      >
      <Button
        v-else
        variant="destructive"
        :disabled="paidSubscription"
        data-test="organization-delete"
        @click="open = true"
        >Delete organization…</Button
      >
    </div>
    <Dialog
      v-model:open="open"
      title="Delete organization"
      :description="`This applies to ${name}.`"
    >
      <div class="flex flex-col gap-3 text-sm">
        <p class="text-text/70">
          You can restore it for 7 days. After that it and all its projects are gone.
        </p>
        <TypedConfirmField v-model="typed" :expected="slug" :error="errors.confirm" />
      </div>
      <template #footer>
        <Button variant="ghost" type="button" @click="open = false">Cancel</Button>
        <Button
          variant="destructive"
          type="button"
          :disabled="typed !== slug"
          data-test="organization-delete-confirm"
          @click="schedule"
          >Delete organization</Button
        >
      </template>
    </Dialog>
  </Card>
</template>
