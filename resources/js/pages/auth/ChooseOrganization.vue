<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Checkbox from '../../components/ui/Checkbox.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
import type { OrgRole } from '../../types/shared';

interface OrganizationChoice {
  id: string;
  slug: string;
  name: string;
  role: OrgRole;
  projectCount: number;
}

const props = defineProps<{ organizations: OrganizationChoice[]; remember: boolean }>();

const form = useForm({ organization: '', remember: props.remember });

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
  <AuthLayout title="Choose an organization" subtitle="You belong to more than one.">
    <div class="flex flex-col gap-4">
      <ul class="flex flex-col gap-2" aria-label="Organizations">
        <li v-for="organization in organizations" :key="organization.id">
          <button
            type="button"
            class="border-control-border hover:bg-hover flex w-full items-center justify-between gap-3 rounded-md border px-3 py-2.5 text-left"
            :disabled="form.processing"
            :data-test="`choose-${organization.slug}`"
            @click="choose(organization.slug)"
          >
            <span class="flex flex-col">
              <span class="text-sm font-medium">{{ organization.name }}</span>
              <span class="text-muted text-xs">
                {{ roleLabel[organization.role] }} · {{ projects(organization.projectCount) }}
              </span>
            </span>
            <span class="text-muted text-xs" aria-hidden="true">Open</span>
          </button>
        </li>
      </ul>
      <Checkbox
        v-model="form.remember"
        label="Remember my choice"
        help="Skip this screen next time you sign in. You can switch any time from the sidebar."
        data-test="choose-remember"
      />
    </div>
  </AuthLayout>
</template>
