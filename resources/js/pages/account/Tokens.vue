<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue';
import EmptyState from '../../components/ui/EmptyState.vue';
import TokenTable, { type TokenRow } from '../../features/credentials/TokenTable.vue';
import AccountLayout from '../../layouts/AccountLayout.vue';

defineProps<{ tokens: TokenRow[] }>();

const revoking = ref<TokenRow | null>(null);
const open = computed({
  get: () => revoking.value !== null,
  set: (value) => {
    if (!value) revoking.value = null;
  },
});

function revoke(): void {
  const token = revoking.value;
  if (token === null) return;
  router.post(
    `/account/tokens/${token.id}/revoke`,
    {},
    { preserveScroll: true, onFinish: () => (revoking.value = null) },
  );
}
</script>

<template>
  <Head title="API tokens" />
  <AccountLayout title="API tokens">
    <section class="flex flex-col gap-3" aria-labelledby="account-tokens">
      <div>
        <h2 id="account-tokens" class="font-heading text-lg">Your personal tokens</h2>
        <p class="text-text/70 text-sm">
          Every personal token you made, in every project. Make a new one on a project's API tokens
          page.
        </p>
      </div>
      <EmptyState
        v-if="tokens.length === 0"
        title="No tokens yet"
        message="Personal tokens you make for the CLI and scripts show up here."
      />
      <TokenTable v-else :tokens="tokens" show-project @revoke="revoking = $event" />
    </section>
    <ConfirmDialog
      v-model:open="open"
      title="Revoke token"
      :object-name="revoking?.label ?? ''"
      description="Anything using it stops working. This can't be undone."
      confirm-label="Revoke"
      destructive
      @confirm="revoke"
    />
  </AccountLayout>
</template>
