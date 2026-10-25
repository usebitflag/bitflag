/** `data-test` selectors shared by the specs. Specs never select by class or tag. */
export const sel = (name: string): string => `[data-test="${name}"]`;

export const selectors = {
  login: {
    email: `${sel('login-email')} input`,
    password: `${sel('login-password')} input`,
    submit: sel('login-submit'),
    error: sel('login-error'),
  },
  shell: {
    layout: sel('portal-layout'),
    sidebar: sel('sidebar'),
    navItem: sel('nav-item'),
    topBar: sel('top-bar'),
    environmentPicker: sel('environment-picker'),
    environmentKey: sel('environment-key'),
    environmentRevision: sel('environment-revision'),
    projectSwitcher: sel('project-switcher'),
    switcherMenu: sel('switcher-menu'),
    userMenu: sel('user-menu'),
    userName: sel('user-name'),
    userEmail: sel('user-email'),
    viewerNotice: sel('viewer-notice'),
    drawerToggle: sel('drawer-toggle'),
    pageTitle: sel('page-title'),
  },
  error: {
    page: sel('error-page'),
    status: sel('error-status'),
    title: sel('error-title'),
    requestId: sel('error-request-id'),
  },
  gallery: {
    toastButton: sel('gallery-toast'),
    confirmButton: sel('gallery-confirm'),
    toast: sel('toast'),
    dialog: sel('dialog'),
    confirmInput: sel('confirm-input'),
    confirmSubmit: sel('confirm-submit'),
    menu: sel('menu'),
    table: sel('data-table'),
  },
  theme: {
    light: sel('segment-light'),
    dark: sel('segment-dark'),
    system: sel('segment-system'),
  },
} as const;
