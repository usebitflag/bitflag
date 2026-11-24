<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Banner from '../components/ui/Banner.vue';
import ToastViewport from '../components/ui/ToastViewport.vue';
import ProjectStatusBanner from '../components/ui/ProjectStatusBanner.vue';
import VerifyEmailBanner from '../components/ui/VerifyEmailBanner.vue';
import SessionExpiredDialog from '../features/auth/SessionExpiredDialog.vue';
import CommandPalette from '../features/shell/CommandPalette.vue';
import EnvironmentPicker from '../features/shell/EnvironmentPicker.vue';
import ProjectSwitcher from '../features/shell/ProjectSwitcher.vue';
import SidebarNav from '../features/shell/SidebarNav.vue';
import TopBar from '../features/shell/TopBar.vue';
import UserMenu from '../features/shell/UserMenu.vue';
import { useRememberScope } from '../composables/useRememberScope';
import { useToast } from '../composables/useToast';
import type { SharedProps } from '../types/shared';

withDefaults(defineProps<{ context?: string }>(), { context: '' });

const page = usePage<SharedProps>();
const drawerOpen = ref(false);
const sidebar = ref<HTMLElement | null>(null);
// From 1024px the sidebar is always visible (a rail, then the full sidebar). Below that it is a drawer, and while the
// drawer is closed nothing inside it can take focus.
const desktop = ref(true);
let media: MediaQueryList | null = null;
const syncDesktop = () => (desktop.value = media?.matches ?? true);
const { toast } = useToast();

useRememberScope();

const scope = computed(() => page.props.scope);
const navigation = computed(() => page.props.navigation);
const isViewer = computed(() => scope.value.projectRole === 'viewer');
const organizationSettingsHref = computed(() => {
  const organization = scope.value.organization;
  return organization && page.props.can['org.manage']
    ? `/${organization.slug}/settings`
    : undefined;
});

// A toast flashed by the server shows once, on the page that follows the action.
watch(
  () => page.props.flash.toast,
  (flashed) => {
    if (flashed) toast(flashed.message, { tone: flashed.tone });
  },
  { immediate: true },
);

onMounted(() => {
  if (typeof window.matchMedia !== 'function') return;
  media = window.matchMedia('(min-width: 1024px)');
  syncDesktop();
  media.addEventListener('change', syncDesktop);
});
onBeforeUnmount(() => media?.removeEventListener('change', syncDesktop));

function closeDrawer(restoreFocus = true): void {
  if (!drawerOpen.value) return;
  drawerOpen.value = false;
  if (restoreFocus) document.querySelector<HTMLElement>('[data-test="drawer-toggle"]')?.focus();
}

async function toggleDrawer(): Promise<void> {
  if (drawerOpen.value) return closeDrawer();
  drawerOpen.value = true;
  await nextTick();
  sidebar.value?.querySelector<HTMLElement>('a, button')?.focus();
}

// Following a link closes the drawer.
watch(
  () => page.url,
  () => closeDrawer(false),
);
</script>

<template>
  <div class="bg-bg text-text min-h-screen" data-test="portal-layout">
    <a
      href="#main"
      class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded-md focus:bg-surface focus:px-3 focus:py-2"
      >Skip to content</a
    >

    <div
      v-if="drawerOpen"
      class="fixed inset-0 z-30 bg-black/40 lg:hidden"
      data-test="drawer-backdrop"
      @click="drawerOpen = false"
    />

    <aside
      ref="sidebar"
      :inert="!desktop && !drawerOpen"
      :class="[
        'bg-surface border-divider fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r transition-transform',
        'lg:w-14 lg:translate-x-0 xl:w-64',
        drawerOpen ? 'translate-x-0' : '-translate-x-full',
      ]"
      aria-label="Primary"
      data-test="sidebar"
      @keydown.esc="closeDrawer()"
    >
      <slot name="sidebar" :expanded="drawerOpen">
        <div class="border-divider border-b"><ProjectSwitcher :expanded="drawerOpen" /></div>
        <div class="border-divider border-b p-2">
          <EnvironmentPicker
            :environments="page.props.environments"
            :current="scope.environment"
            :path="page.url"
            :expanded="drawerOpen"
          />
        </div>
        <div class="flex-1 overflow-y-auto">
          <SidebarNav :items="navigation" :expanded="drawerOpen" />
        </div>
        <div v-if="page.props.auth.user" class="border-divider border-t">
          <UserMenu
            :user="page.props.auth.user"
            :organization-settings-href="organizationSettingsHref"
          />
        </div>
      </slot>
    </aside>

    <div class="flex min-h-screen flex-col lg:pl-14 xl:pl-64">
      <TopBar
        :context="context || scope.project?.name || ''"
        :environment-name="scope.environment?.name ?? null"
        @toggle-drawer="toggleDrawer"
      >
        <template v-if="$slots.context" #context><slot name="context" /></template>
      </TopBar>
      <main id="main" class="flex flex-1 flex-col gap-4 p-6" tabindex="-1">
        <VerifyEmailBanner v-if="page.props.auth.user && !page.props.auth.user.emailVerified" />
        <ProjectStatusBanner
          v-if="page.props.scope.project && page.props.scope.project.status !== 'active'"
          :project-name="page.props.scope.project.name"
          :status="page.props.scope.project.status"
          :deletion-due-at="page.props.scope.project.deletionDueAt"
          :can-restore="page.props.scope.projectRole === 'owner'"
          :action="`/${page.props.scope.organization?.slug}/${page.props.scope.project.key}/${page.props.scope.environment?.key}/settings/danger`"
        />
        <Banner v-if="isViewer" tone="neutral" label="Read-only." data-test="viewer-notice">
          You have the Viewer role on this project, so you can look but not change anything.
        </Banner>
        <slot />
      </main>
    </div>

    <CommandPalette :navigation="navigation" />
    <ToastViewport />
    <SessionExpiredDialog />
  </div>
</template>
