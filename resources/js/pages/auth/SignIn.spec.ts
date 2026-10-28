import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const form = reactive({
  email: '',
  password: '',
  errors: {} as Record<string, string>,
  processing: false,
  post: vi.fn(),
  reset: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => null },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: () => form,
  usePage: () => ({ props: { errors: {} } }),
}));

import SignIn from './SignIn.vue';

describe('sign-in page', () => {
  it('shows the fields and posts them to /signin', async () => {
    const page = mount(SignIn, { props: { providers: [] } });

    await page.get('[data-test="login-email"] input').setValue('maya@acme.dev');
    await page.get('[data-test="login-password"] input').setValue('secret');
    await page.get('form').trigger('submit');

    expect(form.email).toBe('maya@acme.dev');
    expect(form.post).toHaveBeenCalledWith('/signin', expect.any(Object));
  });

  it('shows a sign-in error next to the email field and marks it invalid', async () => {
    form.errors = { email: "That email and password don't match. Check them and try again." };
    const page = mount(SignIn, { props: { providers: [] } });

    expect(page.get('[data-test="login-error"]').text()).toContain("don't match");
    expect(page.get('[data-test="login-email"] input').attributes('aria-invalid')).toBe('true');
  });

  it('links to the password reset', () => {
    expect(
      mount(SignIn, { props: { providers: [] } })
        .get('[data-test="login-forgot"]')
        .attributes('href'),
    ).toBe('/forgot-password');
  });

  it('disables the button while the request runs', () => {
    form.processing = true;

    expect(
      mount(SignIn, { props: { providers: [] } })
        .get('[data-test="login-submit"]')
        .attributes('disabled'),
    ).toBeDefined();
  });
});

describe('sign-in with a provider', () => {
  it('shows the buttons for the providers that are set up, as plain links', () => {
    const page = mount(SignIn, {
      props: {
        providers: [
          { key: 'github', label: 'GitHub' },
          { key: 'google', label: 'Google' },
        ],
      },
    });

    expect(page.get('[data-test="social-github"]').attributes('href')).toBe(
      '/auth/github/redirect',
    );
    expect(page.get('[data-test="social-google"]').text()).toBe('Continue with Google');
  });

  it('shows no buttons when no provider is set up', () => {
    expect(
      mount(SignIn, { props: { providers: [] } })
        .find('[data-test="social-buttons"]')
        .exists(),
    ).toBe(false);
  });
});
