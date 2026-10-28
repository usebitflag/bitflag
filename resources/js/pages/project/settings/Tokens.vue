<script setup lang="ts">
import { PhPlus } from '@phosphor-icons/vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../../../components/ui/Button.vue';
import Card from '../../../components/ui/Card.vue';
import ConfirmDialog from '../../../components/ui/ConfirmDialog.vue';
import EmptyState from '../../../components/ui/EmptyState.vue';
import StatusTag from '../../../components/ui/StatusTag.vue';
import CreateTokenDialog from '../../../features/credentials/CreateTokenDialog.vue';
import ScopeChips from '../../../features/credentials/ScopeChips.vue';
import TokenTable, { type TokenRow } from '../../../features/credentials/TokenTable.vue';
import ProjectSettingsLayout from '../../../layouts/ProjectSettingsLayout.vue';
import type { SharedProps } from '../../../types/shared';

interface ManagementKeyRow {
  id: string;
  label: string;
  masked: string;
  scopes: string[];
  environment: { key: string; name: string } | null;
  state: 'active' | 'rotating' | 'expired' | 'revoked';
  createdAt: number;
  lastUsedAt: number | null;
  expiresAt: number | null;
}

defineProps<{
  tokens: TokenRow[];
  managementKeys: ManagementKeyRow[];
  canDraft: boolean;
  canPublish: boolean;
}>();

const page = usePage<SharedProps>();
const base = computed(() => {
  const { organization, project, environment } = page.props.scope;
  return `/${organization?.slug}/${project?.key}/${environment?.key}/settings`;
});
const hasPassword = computed(() => page.props.auth.user?.hasPassword !== false);
const projectName = computed(() => page.props.scope.project?.name ?? 'this project');

const role = computed(() => page.props.scope.projectRole);
const roleLabel = computed(() =>
  role.value ? role.value.charAt(0).toUpperCase() + role.value.slice(1) : undefined,
);
const creating = ref(false);
const revoking = ref<TokenRow | null>(null);
const revokeOpen = computed({
  get: () => revoking.value !== null,
  set: (value) => {
    if (!value) revoking.value = null;
  },
});

function revoke(): void {
  const token = revoking.value;
  if (token === null) return;
  router.post(
    `${base.value}/tokens/${token.id}/revoke`,
    {},
    { preserveScroll: true, onFinish: () => (revoking.value = null) },
  );
}

function refresh(): void {
  router.reload({ only: ['tokens'] });
}

const stateLabel = {
  active: 'Active',
  rotating: 'Rotating',
  expired: 'Expired',
  revoked: 'Revoked',
} as const;
</script>

<template>
  <Head title="API tokens" />
  <ProjectSettingsLayout
    tab="tokens"
    title="API tokens"
    description="Personal tokens let the CLI and your scripts act as you, with your role. For SDKs, use the keys on each environment instead."
    :can-manage="true"
  >
    <template #actions>
      <Button variant="primary" data-test="token-new" @click="creating = true">
        <template #icon><PhPlus :size="16" aria-hidden="true" /></template>
        Create token
      </Button>
    </template>

    <EmptyState
      v-if="tokens.length === 0"
      title="No tokens"
      message="Create one to use the CLI in scripts, or run bitflag login to sign in with your browser instead."
    >
      <template #actions>
        <Button variant="primary" data-test="token-new-empty" @click="creating = true"
          >Create token</Button
        >
      </template>
    </EmptyState>
    <TokenTable v-else :tokens="tokens" @revoke="revoking = $event" />

    <section class="flex flex-col gap-3" aria-labelledby="management-heading">
      <h2 id="management-heading" class="font-heading text-lg">Project management keys</h2>
      <Card>
        <p class="text-text/70 mb-3 text-sm">
          Management keys belong to the project and keep working when their creator leaves. They are
          made and rotated on each environment's Keys tab.
        </p>
        <p
          v-if="managementKeys.length === 0"
          class="text-text/62 text-sm"
          data-test="no-management-keys"
        >
          No management keys yet.
        </p>
        <table v-else class="w-full text-left text-sm" data-test="management-keys">
          <caption class="sr-only">
            Project management keys
          </caption>
          <thead class="text-text/62 text-xs">
            <tr>
              <th scope="col" class="py-1 font-medium">Name</th>
              <th scope="col" class="py-1 font-medium">Environment</th>
              <th scope="col" class="py-1 font-medium">Scopes</th>
              <th scope="col" class="py-1 font-medium">State</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="key in managementKeys"
              :key="key.id"
              class="border-divider border-t"
              data-test="management-key-row"
            >
              <td class="py-2">
                <div class="font-medium">{{ key.label }}</div>
                <code class="text-text/62 font-mono text-xs">{{ key.masked }}</code>
              </td>
              <td class="py-2">
                <Link
                  v-if="key.environment"
                  :href="`${base}/environments/${key.environment.key}/keys`"
                  class="hover:underline"
                  >{{ key.environment.name }}</Link
                >
              </td>
              <td class="py-2"><ScopeChips :scopes="key.scopes" /></td>
              <td class="py-2"><StatusTag :status="stateLabel[key.state]" /></td>
            </tr>
          </tbody>
        </table>
      </Card>
    </section>

    <CreateTokenDialog
      v-model:open="creating"
      :action="`${base}/tokens`"
      :project-name="projectName"
      :can-draft="canDraft"
      :can-publish="canPublish"
      :has-password="hasPassword"
      :role="roleLabel"
      @created="refresh"
    />
    <ConfirmDialog
      v-model:open="revokeOpen"
      title="Revoke token"
      :object-name="revoking?.label ?? ''"
      description="Anything using it stops working. This can't be undone."
      confirm-label="Revoke"
      destructive
      @confirm="revoke"
    />
  </ProjectSettingsLayout>
</template>
