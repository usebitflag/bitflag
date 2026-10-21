import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { installNavigationGuard } from './composables/useNavigationGuard';
import { initTheme } from './composables/useTheme';

initTheme();
installNavigationGuard();

const appName = import.meta.env.VITE_APP_NAME ?? 'BitFlag';

// The component gallery is a development page. A production build leaves it out; the end-to-end build sets
// VITE_INCLUDE_DEV_PAGES so the browser specs can reach it, and the dev server always includes it.
const includeDevPages = import.meta.env.DEV || import.meta.env.VITE_INCLUDE_DEV_PAGES === 'true';
const pages = {
  ...import.meta.glob<DefineComponent>(['./pages/**/*.vue', '!./pages/dev/**']),
  ...(includeDevPages ? import.meta.glob<DefineComponent>('./pages/dev/**/*.vue') : {}),
};

void createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: async (name) => {
    const page = pages[`./pages/${name}.vue`];
    if (!page) throw new Error(`Unknown page component: ${name}`);
    return page();
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el);
  },
});
