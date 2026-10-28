<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import OrgSettingsLayout from '../../layouts/OrgSettingsLayout.vue';
import type { SharedProps } from '../../types/shared';

interface ProjectRow {
  id: string;
  key: string;
  name: string;
  environments: number;
  members: number;
  isMember: boolean;
}

defineProps<{ projects: ProjectRow[]; canJoin: boolean }>();

const page = usePage<SharedProps>();
const slug = computed(() => page.props.scope.organization?.slug ?? '');

function join(project: ProjectRow): void {
  router.post(`/${slug.value}/settings/projects/${project.id}/join`, {}, { preserveScroll: true });
}
</script>

<template>
  <Head title="Projects" />
  <OrgSettingsLayout title="Projects">
    <p v-if="projects.length === 0" class="text-muted text-sm" data-test="projects-empty">
      This organization has no projects yet.
    </p>
    <div v-else class="border-divider overflow-x-auto rounded-lg border">
      <table class="w-full border-collapse text-sm">
        <caption class="sr-only">
          Projects in the organization
        </caption>
        <thead>
          <tr class="border-divider text-muted border-b text-left">
            <th scope="col" class="px-4 py-2">Project</th>
            <th scope="col" class="px-4 py-2">Environments</th>
            <th scope="col" class="px-4 py-2">Members</th>
            <th scope="col" class="px-4 py-2"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="project in projects"
            :key="project.id"
            class="border-divider border-b last:border-0"
            data-test="project-row"
          >
            <td class="px-4 py-2">
              <div class="font-medium">{{ project.name }}</div>
              <div class="text-muted font-mono text-xs">{{ project.key }}</div>
            </td>
            <td class="px-4 py-2">{{ project.environments }}</td>
            <td class="px-4 py-2">{{ project.members }}</td>
            <td class="px-4 py-2 text-right">
              <Button
                v-if="canJoin && !project.isMember"
                size="sm"
                data-test="project-join"
                @click="join(project)"
                >Join as Owner</Button
              >
              <span v-else-if="project.isMember" class="text-muted text-xs">You're a member</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </OrgSettingsLayout>
</template>
