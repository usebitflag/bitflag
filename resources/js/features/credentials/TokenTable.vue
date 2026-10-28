<script setup lang="ts">
import Button from '../../components/ui/Button.vue';
import StatusTag from '../../components/ui/StatusTag.vue';

export interface TokenRow {
  id: string;
  label: string;
  masked: string;
  access: 'read' | 'draft' | 'publish';
  createdAt: number;
  lastUsedAt: number | null;
  expiresAt: number | null;
  state: 'active' | 'rotating' | 'expired' | 'revoked';
  project?: { key: string; name: string } | null;
  organization?: { slug: string; name: string } | null;
}

defineProps<{ tokens: TokenRow[]; showProject?: boolean }>();
const emit = defineEmits<{ revoke: [TokenRow] }>();

const accessLabel = {
  read: 'Read only',
  draft: 'Read and edit drafts',
  publish: 'Read, edit and publish',
} as const;
const stateLabel = {
  active: 'Active',
  rotating: 'Active',
  expired: 'Expired',
  revoked: 'Revoked',
} as const;

function when(seconds: number | null, empty: string): string {
  return seconds === null ? empty : new Date(seconds * 1000).toLocaleDateString();
}
</script>

<template>
  <div class="border-divider overflow-x-auto rounded-lg border">
    <table class="w-full text-left text-sm" data-test="tokens-table">
      <caption class="sr-only">
        Personal access tokens
      </caption>
      <thead class="text-text/62 border-divider border-b text-xs">
        <tr>
          <th scope="col" class="px-3 py-2 font-medium">Name</th>
          <th v-if="showProject" scope="col" class="px-3 py-2 font-medium">Project</th>
          <th scope="col" class="px-3 py-2 font-medium">Access</th>
          <th scope="col" class="px-3 py-2 font-medium">Created</th>
          <th scope="col" class="px-3 py-2 font-medium">Last used</th>
          <th scope="col" class="px-3 py-2 font-medium">Expires</th>
          <th scope="col" class="px-3 py-2 font-medium">State</th>
          <th scope="col" class="px-3 py-2"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="token in tokens"
          :key="token.id"
          class="border-divider border-b last:border-b-0"
          data-test="token-row"
        >
          <td class="px-3 py-2">
            <div class="font-medium">{{ token.label }}</div>
            <code class="text-text/62 font-mono text-xs">{{ token.masked }}</code>
          </td>
          <td v-if="showProject" class="px-3 py-2">
            {{ token.project?.name ?? '—' }}
            <div class="text-text/62 text-xs">{{ token.organization?.name }}</div>
          </td>
          <td class="px-3 py-2">{{ accessLabel[token.access] }}</td>
          <td class="text-text/62 px-3 py-2">{{ when(token.createdAt, '—') }}</td>
          <td class="text-text/62 px-3 py-2">{{ when(token.lastUsedAt, 'Never') }}</td>
          <td class="text-text/62 px-3 py-2">{{ when(token.expiresAt, 'Never') }}</td>
          <td class="px-3 py-2"><StatusTag :status="stateLabel[token.state]" /></td>
          <td class="px-3 py-2 text-right">
            <Button
              v-if="token.state === 'active' || token.state === 'rotating'"
              size="sm"
              variant="secondary"
              data-test="token-revoke"
              @click="emit('revoke', token)"
              >Revoke</Button
            >
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
