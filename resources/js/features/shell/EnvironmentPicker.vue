<script setup lang="ts">
import { PhCaretDown, PhCheck } from '@phosphor-icons/vue';
import { router } from '@inertiajs/vue3';
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui';
import { computed, ref } from 'vue';
import type { SharedEnvironment } from '../../types/shared';
import { confirmLeave } from '../../composables/useNavigationGuard';
import { SEARCH_THRESHOLD, urlForEnvironment } from './environment';

const props = withDefaults(
  defineProps<{
    environments: SharedEnvironment[];
    current: SharedEnvironment | null;
    /** The current path, so switching keeps the page and swaps only the environment segment. */
    path?: string;
    /** Set when environment management exists; the link is hidden until then. */
    manageHref?: string;
    expanded?: boolean;
  }>(),
  { path: '', manageHref: undefined, expanded: false },
);

const open = ref(false);
const search = ref('');

const visible = computed(() => {
  const term = search.value.trim().toLowerCase();
  return term === ''
    ? props.environments
    : props.environments.filter(
        (environment) =>
          environment.key.includes(term) || environment.name.toLowerCase().includes(term),
      );
});

const showSearch = computed(() => props.environments.length > SEARCH_THRESHOLD);
const revisionLabel = (environment: SharedEnvironment | null) =>
  environment?.revision != null ? `rev ${environment.revision}` : 'no revision';

function choose(environment: SharedEnvironment): void {
  open.value = false;
  if (environment.key === props.current?.key) return;
  if (!confirmLeave()) return;
  router.visit(urlForEnvironment(props.path || window.location.pathname, environment.key));
}
</script>

<template>
  <PopoverRoot v-model:open="open">
    <PopoverTrigger
      class="border-control-border bg-bg flex h-[34px] w-full items-center gap-2 rounded-md border px-2.5 text-sm"
      aria-haspopup="listbox"
      :aria-label="`Environment: ${current?.key ?? 'none'}, ${revisionLabel(current)}`"
      data-test="environment-picker"
    >
      <span class="bg-primary h-2 w-2 shrink-0 rounded-full" aria-hidden="true" />
      <span
        :class="['flex-1 truncate text-left font-medium', expanded ? '' : 'hidden xl:inline']"
        data-test="environment-key"
        >{{ current?.key ?? '—' }}</span
      >
      <span
        :class="['tnum text-muted text-xs', expanded ? '' : 'hidden xl:inline']"
        data-test="environment-revision"
        >{{ revisionLabel(current) }}</span
      >
      <PhCaretDown :size="12" aria-hidden="true" :class="expanded ? '' : 'hidden xl:block'" />
    </PopoverTrigger>
    <PopoverPortal>
      <PopoverContent
        align="start"
        :side-offset="4"
        class="bg-popover border-divider shadow-md z-50 w-64 rounded-md border p-1"
        data-test="environment-menu"
      >
        <input
          v-if="showSearch"
          v-model="search"
          type="search"
          aria-label="Search environments"
          placeholder="Search environments"
          class="border-control-border bg-surface mb-1 h-8 w-full rounded-sm border px-2 text-sm"
          data-test="environment-search"
        />
        <ul role="listbox" aria-label="Environments" class="flex flex-col">
          <li
            v-for="environment in visible"
            :key="environment.id"
            role="option"
            tabindex="0"
            :aria-selected="environment.key === current?.key"
            class="hover:bg-hover flex h-8 cursor-default items-center gap-2 rounded-sm px-2 text-sm"
            :data-test="`environment-option-${environment.key}`"
            @click="choose(environment)"
            @keydown.enter.prevent="choose(environment)"
          >
            <PhCheck
              :size="14"
              :class="environment.key === current?.key ? '' : 'invisible'"
              aria-hidden="true"
            />
            <span class="flex-1 font-medium">{{ environment.key }}</span>
            <span class="tnum text-muted text-xs">{{ revisionLabel(environment) }}</span>
          </li>
        </ul>
        <template v-if="manageHref">
          <div class="bg-divider my-1 h-px" />
          <a
            :href="manageHref"
            class="hover:bg-hover flex h-8 items-center rounded-sm px-2 text-sm"
            data-test="manage-environments"
            >Manage environments</a
          >
        </template>
      </PopoverContent>
    </PopoverPortal>
  </PopoverRoot>
</template>
