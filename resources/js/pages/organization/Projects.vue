<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
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
  <OrgSettingsLayout
    title="Projects"
    description="Every project in this organization, and who is on it."
  >
    <div class="max-w-[820px]">
      <Card v-if="projects.length === 0">
        <p class="text-text/65 text-[13px]" data-test="projects-empty">
          This organization has no projects yet.
        </p>
      </Card>
      <div v-else class="bg-surface border-divider overflow-hidden rounded-lg border">
        <table class="w-full border-collapse text-[13px]">
          <caption class="sr-only">
            Projects in the organization
          </caption>
          <thead>
            <tr class="text-text/55 border-divider border-b text-left">
              <th scope="col" class="py-1.5 pr-4 pl-[18px] font-medium">Project</th>
              <th scope="col" class="px-4 py-1.5 font-medium">Environments</th>
              <th scope="col" class="px-4 py-1.5 font-medium">Members</th>
              <th scope="col" class="px-4 py-1.5"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="project in projects"
              :key="project.id"
              class="border-divider hover:bg-text/[0.025] border-b last:border-0"
              data-test="project-row"
            >
              <td class="py-2 pr-4 pl-[18px]">
                <span class="flex flex-col gap-0.5">
                  <span>{{ project.name }}</span>
                  <span class="text-text/58 font-mono text-[12.5px]">{{ project.key }}</span>
                </span>
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
                <span v-else-if="project.isMember" class="text-text/55 text-xs"
                  >You're a member</span
                >
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </OrgSettingsLayout>
</template>
