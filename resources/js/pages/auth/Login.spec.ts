import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const form = reactive({
  email: '',
  password: '',
  remember: false,
  errors: {} as Record<string, string>,
  processing: false,
  post: vi.fn(),
  reset: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null }, useForm: () => form }));

import Login from './Login.vue';

describe('sign-in page', () => {
  it('shows the fields and posts them to /signin', async () => {
    const page = mount(Login);

    await page.get('[data-test="login-email"]').setValue('maya@acme.dev');
    await page.get('[data-test="login-password"]').setValue('secret');
    await page.get('form').trigger('submit');

    expect(form.email).toBe('maya@acme.dev');
    expect(form.post).toHaveBeenCalledWith('/signin', expect.any(Object));
  });

  it('shows a sign-in error next to the email field and marks it invalid', async () => {
    form.errors = { email: 'These credentials do not match our records.' };
    const page = mount(Login);

    expect(page.get('[data-test="login-error"]').text()).toContain('do not match');
    expect(page.get('[data-test="login-email"]').attributes('aria-invalid')).toBe('true');
  });

  it('disables the button while the request runs', () => {
    form.processing = true;

    expect(mount(Login).get('[data-test="login-submit"]').attributes('disabled')).toBeDefined();
  });
});
