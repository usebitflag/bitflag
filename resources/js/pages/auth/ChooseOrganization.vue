<script setup lang="ts">
import { PhCaretRight } from '@phosphor-icons/vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Checkbox from '../../components/ui/Checkbox.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
import type { OrgRole, SharedProps } from '../../types/shared';

interface OrganizationChoice {
  id: string;
  slug: string;
  name: string;
  role: OrgRole;
  projectCount: number;
}

const props = defineProps<{ organizations: OrganizationChoice[]; remember: boolean }>();

const form = useForm({ organization: '', remember: props.remember });
const email = computed(() => (usePage().props as unknown as SharedProps).auth?.user?.email);

function choose(slug: string): void {
  form.organization = slug;
  form.post('/organizations/choose');
}

const roleLabel: Record<OrgRole, string> = { owner: 'Org Owner', member: 'Member' };

function projects(count: number): string {
  return `${count} ${count === 1 ? 'project' : 'projects'}`;
}
</script>

<template>
  <Head title="Choose organization" />
  <AuthLayout
    title="Choose an organization"
    :subtitle="email ? `Signed in as ${email}` : 'You belong to more than one.'"
  >
    <ul class="flex flex-col gap-2" aria-label="Organizations">
      <li v-for="organization in organizations" :key="organization.id">
        <button
          type="button"
          class="bg-surface hover:ring-primary grid w-full grid-cols-[32px_minmax(0,1fr)_auto] items-center gap-3 rounded-md px-3 py-2.5 text-left shadow-sm"
          :disabled="form.processing"
          :data-test="`choose-${organization.slug}`"
          @click="choose(organization.slug)"
        >
          <span
            class="bg-selection text-primary-ink grid h-8 w-8 place-items-center rounded-[6px] text-sm"
            aria-hidden="true"
            >{{ organization.name.slice(0, 1).toUpperCase() }}</span
          >
          <span>
            <span class="block text-[14px]">{{ organization.name }}</span>
            <span class="text-text/55 block text-xs">
              {{ roleLabel[organization.role] }} · {{ projects(organization.projectCount) }}
            </span>
          </span>
          <PhCaretRight :size="14" class="text-text/50" aria-hidden="true" />
        </button>
      </li>
    </ul>
    <Checkbox v-model="form.remember" label="Remember my choice" data-test="choose-remember" />
    <template #aside>
      <button
        type="button"
        class="text-text/65 hover:text-text text-sm"
        @click="router.post('/signout')"
      >
        Sign out
      </button>
    </template>
  </AuthLayout>
</template>
