<script setup lang="ts">
import { PhArrowDown, PhArrowUp, PhPlus } from '@phosphor-icons/vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../../../../components/ui/Button.vue';
import IconButton from '../../../../components/ui/IconButton.vue';
import InlineNotice from '../../../../components/ui/InlineNotice.vue';
import StatusTag from '../../../../components/ui/StatusTag.vue';
import Tag from '../../../../components/ui/Tag.vue';
import NewEnvironmentDialog from '../../../../features/environments/NewEnvironmentDialog.vue';
import type { EnvironmentRow } from '../../../../features/environments/types';
import ProjectSettingsLayout from '../../../../layouts/ProjectSettingsLayout.vue';
import type { SharedProps } from '../../../../types/shared';

const props = defineProps<{
  environments: (EnvironmentRow & { activeKeys: number })[];
  canManage: boolean;
  colors: string[];
  approvalsAvailable: boolean;
}>();

const page = usePage<SharedProps>();
const base = computed(() => {
  const { organization, project, environment } = page.props.scope;
  return `/${organization?.slug}/${project?.key}/${environment?.key}/settings/environments`;
});
const errors = computed<Record<string, string | undefined>>(() => page.props.errors ?? {});

const active = computed(() =>
  props.environments.filter((environment) => environment.status === 'active'),
);
const archived = computed(() =>
  props.environments.filter((environment) => environment.status !== 'active'),
);
const creating = ref(false);

function move(index: number, by: -1 | 1): void {
  const order = active.value.map((environment) => environment.id);
  const target = index + by;
  if (target < 0 || target >= order.length) return;
  [order[index], order[target]] = [order[target] as string, order[index] as string];
  router.put(`${base.value}/order`, { order }, { preserveScroll: true });
}

function published(environment: EnvironmentRow): string {
  return environment.firstPublishedAt === null
    ? 'Never'
    : new Date(environment.firstPublishedAt * 1000).toLocaleDateString();
}
</script>

<template>
  <Head title="Environments" />
  <ProjectSettingsLayout
    tab="environments"
    title="Environments"
    description="Each environment has its own rules, published revisions and SDK keys. Order here is the order everywhere in the dashboard."
    :can-manage="canManage"
  >
    <template v-if="canManage" #actions>
      <Button variant="primary" data-test="environment-new" @click="creating = true">
        <template #icon><PhPlus :size="16" aria-hidden="true" /></template>
        New environment
      </Button>
    </template>

    <InlineNotice
      v-if="errors.order"
      tone="warning"
      :title="errors.order"
      data-test="order-conflict"
    />

    <div class="border-divider overflow-x-auto rounded-lg border">
      <table class="w-full text-left text-sm" data-test="environments-table">
        <caption class="sr-only">
          Environments in display order
        </caption>
        <thead class="text-text/62 border-divider border-b text-xs">
          <tr>
            <th v-if="canManage" scope="col" class="w-24 px-3 py-2 font-medium">Order</th>
            <th scope="col" class="px-3 py-2 font-medium">Environment</th>
            <th scope="col" class="px-3 py-2 font-medium">Protection</th>
            <th scope="col" class="px-3 py-2 font-medium">Live revision</th>
            <th scope="col" class="px-3 py-2 font-medium">Last publish</th>
            <th scope="col" class="px-3 py-2 font-medium">SDK keys</th>
            <th scope="col" class="px-3 py-2 font-medium">Delivery</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="(environment, index) in active"
            :key="environment.id"
            class="border-divider border-b last:border-b-0"
            data-test="environment-row"
          >
            <td v-if="canManage" class="px-3 py-2">
              <div class="flex gap-1">
                <IconButton
                  :label="`Move ${environment.name} up`"
                  :disabled="index === 0"
                  data-test="move-up"
                  @click="move(index, -1)"
                  ><PhArrowUp :size="14" aria-hidden="true"
                /></IconButton>
                <IconButton
                  :label="`Move ${environment.name} down`"
                  :disabled="index === active.length - 1"
                  data-test="move-down"
                  @click="move(index, 1)"
                  ><PhArrowDown :size="14" aria-hidden="true"
                /></IconButton>
              </div>
            </td>
            <td class="px-3 py-2">
              <div class="flex flex-col">
                <Link
                  :href="`${base}/${environment.key}`"
                  class="font-medium hover:underline"
                  data-test="environment-link"
                  >{{ environment.name }}</Link
                >
                <code class="text-text/62 font-mono text-xs">{{ environment.key }}</code>
              </div>
            </td>
            <td class="px-3 py-2">
              <Tag v-if="environment.isProtected" variant="accent">{{
                environment.requiredApprovals > 0 ? 'Protected · review required' : 'Protected'
              }}</Tag>
              <span v-else class="text-text/62">Open</span>
            </td>
            <td class="px-3 py-2">
              <span class="text-text/62">Not published</span>
            </td>
            <td class="text-text/62 px-3 py-2">{{ published(environment) }}</td>
            <td class="text-text/62 px-3 py-2" data-test="active-keys">
              {{ environment.activeKeys === 0 ? 'None yet' : `${environment.activeKeys} active` }}
            </td>
            <td class="text-text/62 px-3 py-2" data-test="delivery">—</td>
          </tr>
          <tr
            v-for="environment in archived"
            :key="environment.id"
            class="border-divider border-b last:border-b-0"
          >
            <td v-if="canManage" class="px-3 py-2" />
            <td class="text-text/62 px-3 py-2">
              <Link :href="`${base}/${environment.key}`" class="hover:underline">{{
                environment.name
              }}</Link>
              <code class="ml-2 font-mono text-xs">{{ environment.key }}</code>
              <StatusTag status="Archived" class="ml-2" />
            </td>
            <td colspan="5" />
          </tr>
        </tbody>
      </table>
    </div>
    <p class="text-text/62 text-sm" data-test="environments-explainer">
      Environments with published revisions or keys can't be deleted. Archive them instead; their
      history stays in the audit log.
    </p>

    <NewEnvironmentDialog
      v-model:open="creating"
      :action="base"
      :environments="
        active.map((environment) => ({
          id: environment.id,
          name: environment.name,
          key: environment.key,
        }))
      "
      :taken-keys="environments.map((environment) => environment.key)"
      :colors="colors"
      :approvals-available="approvalsAvailable"
      :errors="errors"
    />
  </ProjectSettingsLayout>
</template>
