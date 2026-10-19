import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { initTheme } from './composables/useTheme';

initTheme();

const appName = import.meta.env.VITE_APP_NAME ?? 'BitFlag';

const pages = import.meta.glob<DefineComponent>('./pages/**/*.vue');

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
