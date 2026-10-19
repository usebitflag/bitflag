<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { PhPlus } from '@phosphor-icons/vue';
import { ref } from 'vue';
import Avatar from '../../components/ui/Avatar.vue';
import Banner from '../../components/ui/Banner.vue';
import Breadcrumbs from '../../components/ui/Breadcrumbs.vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
import Checkbox from '../../components/ui/Checkbox.vue';
import ConfirmDialog from '../../components/ui/ConfirmDialog.vue';
import DataTable from '../../components/ui/DataTable.vue';
import DropdownMenu from '../../components/ui/DropdownMenu.vue';
import EmptyState from '../../components/ui/EmptyState.vue';
import EnvLabel from '../../components/ui/EnvLabel.vue';
import Field from '../../components/ui/Field.vue';
import IconButton from '../../components/ui/IconButton.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Input from '../../components/ui/Input.vue';
import Kbd from '../../components/ui/Kbd.vue';
import KeyTag from '../../components/ui/KeyTag.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import RadioGroup from '../../components/ui/RadioGroup.vue';
import SegmentedControl from '../../components/ui/SegmentedControl.vue';
import Select from '../../components/ui/Select.vue';
import Skeleton from '../../components/ui/Skeleton.vue';
import Spinner from '../../components/ui/Spinner.vue';
import StatCard from '../../components/ui/StatCard.vue';
import StatusTag from '../../components/ui/StatusTag.vue';
import Switch from '../../components/ui/Switch.vue';
import Tabs from '../../components/ui/Tabs.vue';
import Tag from '../../components/ui/Tag.vue';
import Textarea from '../../components/ui/Textarea.vue';
import ToastViewport from '../../components/ui/ToastViewport.vue';
import { useTheme, type ThemePreference } from '../../composables/useTheme';
import { useToast } from '../../composables/useToast';

const { preference, setPreference } = useTheme();
const { toast } = useToast();

const text = ref('new-checkout');
const note = ref('');
const select = ref('boolean');
const checked = ref(true);
const enabled = ref(false);
const plan = ref('free');
const tab = ref('keys');
const confirmOpen = ref(false);
const sort = ref<{ key: string; direction: 'asc' | 'desc' } | null>({
  key: 'name',
  direction: 'asc',
});

const statuses = [
  'Active',
  'Healthy',
  'Live',
  'Failing',
  'Rotating',
  'Unpublished changes',
  'Draft',
  'Revoked',
  'Archived',
];
const columns = [
  { key: 'name', label: 'Flag', sortable: true, mono: true },
  { key: 'type', label: 'Type' },
  { key: 'evaluations', label: 'Evaluations', align: 'right' as const },
];
const rows = [
  { id: '1', name: 'new-checkout', type: 'Boolean', evaluations: '34,102' },
  { id: '2', name: 'payment-config', type: 'JSON', evaluations: '8,417' },
];
</script>

<template>
  <Head title="UI gallery" />
  <main class="bg-bg text-text mx-auto flex max-w-4xl flex-col gap-10 p-8" data-test="ui-gallery">
    <PageHeader
      title="UI gallery"
      description="Every baseline component in every state. Local and test environments only."
    >
      <template #actions>
        <SegmentedControl
          label="Theme"
          :model-value="preference"
          :options="[
            { value: 'light', label: 'Light' },
            { value: 'dark', label: 'Dark' },
            { value: 'system', label: 'System' },
          ]"
          @update:model-value="(v) => setPreference(v as ThemePreference)"
        />
      </template>
    </PageHeader>

    <section class="flex flex-col gap-3" aria-labelledby="g-buttons">
      <h2 id="g-buttons" class="font-heading text-lg">Buttons</h2>
      <div class="flex flex-wrap items-center gap-2">
        <Button variant="primary">Primary</Button>
        <Button>Secondary</Button>
        <Button variant="ghost">Ghost</Button>
        <Button variant="destructive">Destructive</Button>
        <Button variant="primary" size="sm">Small</Button>
        <Button variant="primary" loading>Saving</Button>
        <Button disabled>Disabled</Button>
        <Button variant="primary"
          ><template #icon><PhPlus :size="16" /></template>With icon</Button
        >
        <IconButton label="Add"><PhPlus :size="16" /></IconButton>
      </div>
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="g-tags">
      <h2 id="g-tags" class="font-heading text-lg">Tags and status</h2>
      <div class="flex flex-wrap items-center gap-2">
        <Tag variant="accent">Accent</Tag>
        <Tag>Neutral</Tag>
        <Tag variant="outline">Outline</Tag>
        <Tag variant="success" dot>Success</Tag>
        <Tag variant="warning">Warning</Tag>
        <Tag variant="danger">Danger</Tag>
        <Tag mono>new-checkout</Tag>
        <StatusTag v-for="status in statuses" :key="status" :status="status" />
        <EnvLabel environment-key="production" />
        <KeyTag value="new-checkout" />
        <Avatar name="Maya Santos" />
        <Kbd>⌘K</Kbd>
        <Spinner />
      </div>
    </section>

    <section class="flex flex-col gap-4" aria-labelledby="g-forms">
      <h2 id="g-forms" class="font-heading text-lg">Form controls</h2>
      <Field
        v-slot="{ id, describedBy }"
        label="Flag key"
        help="Lowercase letters, digits and dashes."
      >
        <Input :id="id" v-model="text" :described-by="describedBy" mono />
      </Field>
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="Name"
        error="A flag with this name already exists."
      >
        <Input :id="id" model-value="Checkout" :described-by="describedBy" :invalid="invalid" />
      </Field>
      <Field v-slot="{ id }" label="Description"><Textarea :id="id" v-model="note" /></Field>
      <Field v-slot="{ id }" label="Type">
        <Select
          :id="id"
          v-model="select"
          :options="[
            { value: 'boolean', label: 'Boolean' },
            { value: 'string', label: 'String' },
          ]"
        />
      </Field>
      <Checkbox
        v-model="checked"
        label="Client-visible"
        help="Client-visible by default · Leaving this off is safer."
      />
      <Switch v-model="enabled" label="Enabled" />
      <RadioGroup
        v-model="plan"
        label="Plan"
        :options="[
          { value: 'free', label: 'Free', help: '10 flags' },
          { value: 'pro', label: 'Pro' },
        ]"
      />
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="g-data">
      <h2 id="g-data" class="font-heading text-lg">Data</h2>
      <div class="grid gap-3 sm:grid-cols-3">
        <StatCard label="Evaluations" value="34.1M" delta="+15.2%" baseline="vs last week" />
        <StatCard label="Active flags" value="24" delta="+2" />
        <StatCard label="Unique users" value="" unavailable />
      </div>
      <DataTable
        caption="Flags"
        :columns="columns"
        :rows="rows"
        row-key="id"
        :sort="sort"
        @sort="(s) => (sort = s)"
      >
        <template #actions>
          <DropdownMenu
            label="Flag actions"
            :items="[
              { key: 'edit', label: 'Edit' },
              { key: 'archive', label: 'Archive', destructive: true },
            ]"
          >
            <template #trigger><IconButton label="More actions">⋯</IconButton></template>
          </DropdownMenu>
        </template>
      </DataTable>
      <DataTable caption="Loading flags" :columns="columns" :rows="[]" row-key="id" loading />
      <DataTable caption="No flags" :columns="columns" :rows="[]" row-key="id">
        <template #empty>
          <EmptyState title="No flags yet" message="Create a flag to start serving values.">
            <template #actions
              ><Button variant="primary">Create flag</Button
              ><Button variant="ghost">Read the guide</Button></template
            >
          </EmptyState>
        </template>
      </DataTable>
      <Skeleton />
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="g-nav">
      <h2 id="g-nav" class="font-heading text-lg">Navigation and feedback</h2>
      <Breadcrumbs
        :items="[
          { label: 'Acme', href: '#' },
          { label: 'Storefront', href: '#' },
          { label: 'Overview' },
        ]"
      />
      <Tabs
        v-model="tab"
        label="Credentials"
        :tabs="[
          { value: 'keys', label: 'Keys', count: 4 },
          { value: 'tokens', label: 'Tokens' },
        ]"
      >
        <template #keys>Four SDK keys.</template>
        <template #tokens>No tokens.</template>
      </Tabs>
      <Banner label="You're a Viewer.">Everything here is read-only.</Banner>
      <Banner tone="attention" label="Heads up.">This environment has unpublished changes.</Banner>
      <InlineNotice title="Draft changed">Someone saved a newer draft.</InlineNotice>
      <InlineNotice tone="warning" title="Needs a look">Two rules overlap.</InlineNotice>
      <InlineNotice tone="danger" title="Publish failed"
        >The revision did not validate.</InlineNotice
      >
      <Card kicker="Rollout" title="new-checkout" meta="Updated 3 min ago"
        >Serving 50% of users.</Card
      >
      <div class="flex gap-2">
        <Button
          data-test="gallery-toast"
          @click="
            toast('Rollout updated in production', { action: { label: 'Undo', onClick: () => {} } })
          "
        >
          Show a toast
        </Button>
        <Button variant="destructive" data-test="gallery-confirm" @click="confirmOpen = true"
          >Delete flag…</Button
        >
      </div>
    </section>

    <ConfirmDialog
      v-model:open="confirmOpen"
      title="Delete flag"
      object-name="new-checkout"
      confirm-text="new-checkout"
      confirm-label="Delete flag"
      destructive
    />
    <ToastViewport />
  </main>
</template>
