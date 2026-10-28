import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const form = reactive({
  email: '',
  password: '',
  errors: {} as Record<string, string>,
  processing: false,
  delete: vi.fn(),
  reset: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => null },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  usePage: () => ({ url: '/account/delete', props: { auth: { user: { hasPassword: true } } } }),
  router: { post: vi.fn() },
  useForm: () => form,
}));

import Delete from './Delete.vue';

function show(soleOwnerOf: string[] = []) {
  return mount(Delete, {
    props: { email: 'jon@acme.dev', soleOwnerOf },
    global: { stubs: { AccountLayout: { template: '<div><slot /></div>' } } },
  });
}

describe('delete account page', () => {
  it('asks for the email to be typed and the password, and keeps the button off until both are there', async () => {
    form.email = '';
    form.password = '';
    const page = show();

    expect(page.get('[data-test="delete-submit"]').attributes('disabled')).toBeDefined();

    form.email = 'jon@acme.dev';
    form.password = 'secret';
    await page.vm.$nextTick();

    expect(page.get('[data-test="delete-submit"]').attributes('disabled')).toBeUndefined();
  });

  it('sends the deletion', async () => {
    form.email = 'jon@acme.dev';
    form.password = 'secret';

    await show().get('form').trigger('submit');

    expect(form.delete).toHaveBeenCalledWith('/account', expect.any(Object));
  });

  it('names the organizations that stand in the way and blocks the form', () => {
    form.email = 'jon@acme.dev';
    form.password = 'secret';
    const page = show(['Acme', 'Globex']);

    expect(page.get('[data-test="delete-blocked"]').text()).toContain('Acme, Globex');
    expect(page.get('[data-test="delete-submit"]').attributes('disabled')).toBeDefined();
    expect(page.get('[data-test="delete-email"] input').attributes('disabled')).toBeDefined();
  });

  it('shows the reason the server gave', () => {
    form.errors = { account: 'You are the only owner of Acme.' };

    expect(show().get('[data-test="delete-error"]').text()).toContain('only owner of Acme');
    form.errors = {};
  });
});
