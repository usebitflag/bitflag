import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const form = reactive({
  code: '',
  trust: false,
  errors: {} as Record<string, string>,
  processing: false,
  post: vi.fn(),
  reset: vi.fn(),
  clearErrors: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({
  Head: { render: () => null },
  Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
  useForm: () => form,
}));

import TwoFactorChallenge from './TwoFactorChallenge.vue';

const props = { maskedEmail: 'ma••@acme.dev', canUseRecovery: true };

describe('two-factor challenge page', () => {
  it('says who is signing in', () => {
    expect(mount(TwoFactorChallenge, { props }).get('[data-test="challenge-account"]').text()).toBe(
      'Signing in as ma••@acme.dev',
    );
  });

  it('posts the code and the trust choice', async () => {
    const page = mount(TwoFactorChallenge, { props });

    form.code = '123456';
    await page.get('form').trigger('submit');

    expect(form.post).toHaveBeenCalledWith('/two-factor-challenge', expect.any(Object));
  });

  it('switches to a recovery code and back', async () => {
    const page = mount(TwoFactorChallenge, { props });

    expect(page.find('[data-test="otp-input"]').exists()).toBe(true);
    await page.get('[data-test="challenge-toggle"]').trigger('click');

    expect(page.find('[data-test="recovery-input"]').exists()).toBe(true);
    expect(page.find('[data-test="otp-input"]').exists()).toBe(false);
    expect(page.get('[data-test="challenge-toggle"]').text()).toContain('authenticator code');
  });

  it('hides the recovery option when it is not offered', () => {
    const page = mount(TwoFactorChallenge, { props: { ...props, canUseRecovery: false } });

    expect(page.find('[data-test="challenge-toggle"]').exists()).toBe(false);
  });

  it('shows the error from the server', () => {
    form.errors = { code: 'That code is not right.' };

    expect(mount(TwoFactorChallenge, { props }).get('[data-test="challenge-error"]').text()).toBe(
      'That code is not right.',
    );
  });
});
