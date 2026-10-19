<script setup lang="ts">
import { PhCaretUpDown, PhCheck } from '@phosphor-icons/vue';
import { router, usePage } from '@inertiajs/vue3';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, ref, watch } from 'vue';
import { confirmLeave } from '../../composables/useNavigationGuard';
import type { SharedProps } from '../../types/shared';
import BrandMark from './BrandMark.vue';

withDefaults(defineProps<{ expanded?: boolean }>(), { expanded: false });

const page = usePage<SharedProps>();
const open = ref(false);
const scope = computed(() => page.props.scope);
const organizations = computed(() => page.props.switcher?.organizations ?? []);

// The organization and project lists load on demand, with a partial reload, the first time the switcher opens.
watch(open, (isOpen) => {
  if (isOpen && page.props.switcher === undefined) {
    router.reload({ only: ['switcher'] });
  }
});

function go(url: string): void {
  open.value = false;
  if (!confirmLeave()) return;
  router.visit(url);
}
</script>

<template>
  <PopoverRoot v-model:open="open">
    <PopoverTrigger
      class="hover:bg-hover flex h-14 w-full items-center gap-2.5 px-3 text-left"
      aria-haspopup="dialog"
      aria-label="Switch organization or project"
      data-test="project-switcher"
    >
      <span
        class="border-divider bg-surface grid h-8 w-8 shrink-0 place-items-center rounded-md border"
        ><BrandMark
      /></span>
      <span
        :class="['flex min-w-0 flex-1 flex-col leading-tight', expanded ? '' : 'hidden xl:flex']"
      >
        <span class="text-muted truncate text-xs" data-test="switcher-organization">{{
          scope.organization?.name ?? 'BitFlag'
        }}</span>
        <span class="truncate text-md font-medium" data-test="switcher-project">{{
          scope.project?.name ?? 'Choose a project'
        }}</span>
      </span>
      <PhCaretUpDown :size="14" aria-hidden="true" :class="expanded ? '' : 'hidden xl:block'" />
    </PopoverTrigger>
    <PopoverPortal>
      <PopoverContent
        align="start"
        :side-offset="4"
        class="bg-popover border-divider shadow-md z-50 w-72 rounded-md border p-1"
        data-test="switcher-menu"
      >
        <p
          v-if="page.props.switcher === undefined"
          class="text-muted px-2 py-2 text-sm"
          data-test="switcher-loading"
        >
          Loading…
        </p>
        <section
          v-for="organization in organizations"
          :key="organization.id"
          :aria-label="organization.name"
          data-test="switcher-organization-group"
        >
          <h2 class="text-muted px-2 pt-2 pb-1 text-xs font-medium">{{ organization.name }}</h2>
          <ul class="flex flex-col">
            <li v-for="project in organization.projects" :key="project.id">
              <button
                type="button"
                class="hover:bg-hover flex h-8 w-full items-center gap-2 rounded-sm px-2 text-left text-sm"
                :data-test="`switcher-project-${organization.slug}-${project.key}`"
                @click="go(`/${organization.slug}/${project.key}`)"
              >
                <PhCheck
                  :size="14"
                  :class="project.id === scope.project?.id ? '' : 'invisible'"
                  aria-hidden="true"
                />
                <span class="flex-1 truncate">{{ project.name }}</span>
              </button>
            </li>
            <li v-if="organization.projects.length === 0" class="text-muted px-2 py-1 text-sm">
              No projects you can open
            </li>
          </ul>
          <button
            type="button"
            class="hover:bg-hover text-muted flex h-8 w-full items-center rounded-sm px-2 text-left text-sm"
            :data-test="`switcher-org-${organization.slug}`"
            @click="go(`/${organization.slug}`)"
          >
            All of {{ organization.name }}
          </button>
        </section>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
