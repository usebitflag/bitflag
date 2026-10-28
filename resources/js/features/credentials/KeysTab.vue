<script setup lang="ts">
import { PhDotsThree, PhPlus } from '@phosphor-icons/vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../../components/ui/Button.vue';
import DropdownMenu from '../../components/ui/DropdownMenu.vue';
import EmptyState from '../../components/ui/EmptyState.vue';
import IconButton from '../../components/ui/IconButton.vue';
import StatusTag from '../../components/ui/StatusTag.vue';
import { ago, shortDate } from '../../lib/dates';
import type { EnvironmentRow, KeyRow } from '../environments/types';
import CreateKeyWizard from './CreateKeyWizard.vue';
import RevokeKeyDialog from './RevokeKeyDialog.vue';
import RotateKeyDialog from './RotateKeyDialog.vue';
import { KEY_KINDS } from './keyKinds';
import ScopeChips from './ScopeChips.vue';

const props = defineProps<{
  environment: EnvironmentRow;
  keys: KeyRow[];
  /** `/org/project/env/settings/environments/{key}/keys` */
  action: string;
  canManage: boolean;
  allowedOrigins: string[];
  maxSnapshotAgeSeconds: number | null;
}>();

const creating = ref(false);
const rotating = ref<KeyRow | null>(null);
const revoking = ref<KeyRow | null>(null);

const rotateOpen = computed({
  get: () => rotating.value !== null,
  set: (value) => {
    if (!value) rotating.value = null;
  },
});
const revokeOpen = computed({
  get: () => revoking.value !== null,
  set: (value) => {
    if (!value) revoking.value = null;
  },
});

const typeLabel: Record<string, string> = {
  server: 'Server',
  browser: 'Browser',
  management: 'Management',
  personal: 'Personal token',
};
const kindTitle = {
  server: 'Server key',
  browser: 'Browser key',
  management: 'Management key',
} as const;
const stateLabel: Record<KeyRow['state'], string> = {
  active: 'Active',
  rotating: 'Rotating',
  expired: 'Expired',
  revoked: 'Revoked',
};

function when(seconds: number | null, empty: string): string {
  return seconds === null ? empty : shortDate(seconds);
}

function menuItems(key: KeyRow) {
  return [
    { key: 'rotate', label: 'Rotate…', disabled: key.state !== 'active' || !props.canManage },
    { key: 'copy', label: 'Copy key prefix' },
    {
      key: 'revoke',
      label: 'Revoke…',
      destructive: true,
      disabled: !props.canManage || key.state === 'revoked' || key.state === 'expired',
    },
  ];
}

async function select(key: KeyRow, item: string): Promise<void> {
  if (item === 'rotate') rotating.value = key;
  else if (item === 'revoke') revoking.value = key;
  else if (item === 'copy') {
    try {
      await navigator.clipboard.writeText(`${key.prefix}…${key.last4}`);
    } catch {
      // Copying needs a secure page.
    }
  }
}

function refresh(): void {
  router.reload({ only: ['keys', 'environments'] });
}
</script>

<template>
  <div class="flex flex-col gap-4" data-test="environment-keys">
    <div class="flex items-center justify-between gap-3">
      <p class="text-text/70 max-w-[60ch] text-sm">
        Keys are shown once when created and stored hashed. You can't view a key again; rotate it to
        get a new one.
      </p>
      <Button v-if="canManage" variant="primary" data-test="key-new" @click="creating = true">
        <template #icon><PhPlus :size="16" aria-hidden="true" /></template>
        Create key
      </Button>
    </div>

    <EmptyState
      v-if="keys.length === 0"
      title="No keys yet"
      message="Create a server key for backend SDKs or a browser key for web apps. Until something is published here, SDKs using these keys get an empty snapshot and return your fallback values."
    >
      <template v-if="canManage" #actions>
        <Button variant="primary" data-test="key-new-empty" @click="creating = true"
          >Create key</Button
        >
      </template>
    </EmptyState>
    <div v-else class="border-divider overflow-x-auto rounded-lg border">
      <table class="w-full text-left text-sm" data-test="keys-table">
        <caption class="sr-only">
          Keys of
          {{
            environment.name
          }}
        </caption>
        <thead class="text-text/62 border-divider border-b text-xs">
          <tr>
            <th scope="col" class="px-3 py-2 font-medium">Key</th>
            <th scope="col" class="px-3 py-2 font-medium">Type</th>
            <th scope="col" class="px-3 py-2 font-medium">Scopes</th>
            <th scope="col" class="px-3 py-2 font-medium">Created</th>
            <th scope="col" class="px-3 py-2 font-medium">Last used</th>
            <th scope="col" class="px-3 py-2 font-medium">Expires</th>
            <th scope="col" class="px-3 py-2 font-medium">State</th>
            <th scope="col" class="w-10 px-3 py-2"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="key in keys"
            :key="key.id"
            class="border-divider border-b last:border-b-0"
            data-test="key-row"
          >
            <td class="px-3 py-2">
              <div class="font-medium">
                {{ key.label
                }}<span v-if="key.state === 'rotating'" class="text-text/62"> (old)</span>
              </div>
              <code class="text-text/62 font-mono text-xs" data-test="key-masked">{{
                key.masked
              }}</code>
            </td>
            <td class="px-3 py-2">{{ typeLabel[key.type] }}</td>
            <td class="px-3 py-2"><ScopeChips :scopes="key.scopes" /></td>
            <td class="text-text/62 px-3 py-2">
              {{ when(key.createdAt, '—') }}
              <span class="text-xs" data-test="key-creator">
                ·
                <template v-if="key.createdBy.formerMember">created by a former member</template>
                <template v-else>{{ key.createdBy.name }}</template>
              </span>
            </td>
            <td class="text-text/62 px-3 py-2">
              {{ key.lastUsedAt === null ? 'Never' : ago(key.lastUsedAt) }}
              <div v-if="key.lastUsedClient" class="font-mono text-xs">
                {{ key.lastUsedClient }}
              </div>
            </td>
            <td class="text-text/62 px-3 py-2">
              <template v-if="key.state === 'rotating' && key.overlapEndsAt">
                Stops {{ new Date(key.overlapEndsAt * 1000).toLocaleString() }}
              </template>
              <template v-else>{{ when(key.expiresAt, 'Never') }}</template>
            </td>
            <td class="px-3 py-2"><StatusTag :status="stateLabel[key.state]" /></td>
            <td class="px-3 py-2">
              <DropdownMenu
                :items="menuItems(key)"
                :label="`Actions for ${key.label}`"
                @select="select(key, $event)"
              >
                <template #trigger>
                  <IconButton :label="`Actions for ${key.label}`" data-test="key-menu">
                    <PhDotsThree :size="16" aria-hidden="true" />
                  </IconButton>
                </template>
              </DropdownMenu>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ul class="grid gap-3 sm:grid-cols-3" data-test="key-kinds">
      <li v-for="kind in KEY_KINDS" :key="kind.type" class="border-divider rounded-lg border p-3">
        <p class="text-sm font-medium">{{ kindTitle[kind.type] }}</p>
        <p class="text-text/65 mt-1 text-xs">{{ kind.note }}</p>
      </li>
    </ul>

    <CreateKeyWizard
      v-model:open="creating"
      :action="action"
      :environment-name="environment.name"
      :allowed-origins="allowedOrigins"
      @created="refresh"
    />
    <RotateKeyDialog
      v-model:open="rotateOpen"
      :credential="rotating"
      :action="action"
      :environment-name="environment.name"
      @rotated="refresh"
    />
    <RevokeKeyDialog
      v-model:open="revokeOpen"
      :credential="revoking"
      :action="action"
      :max-snapshot-age-seconds="maxSnapshotAgeSeconds"
    />
  </div>
</template>
