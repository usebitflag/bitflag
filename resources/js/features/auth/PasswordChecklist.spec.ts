import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import PasswordChecklist from './PasswordChecklist.vue';

function met(page: ReturnType<typeof mount>, key: string): string | undefined {
  return page.get(`[data-test="password-rule-${key}"]`).attributes('data-met');
}

describe('password checklist', () => {
  it('starts with nothing met', () => {
    const page = mount(PasswordChecklist, { props: { password: '', email: '', common: false } });

    expect(['length', 'common', 'email'].map((key) => met(page, key))).toEqual([
      'false',
      'false',
      'false',
    ]);
  });

  it('counts characters, not bytes', () => {
    const page = mount(PasswordChecklist, {
      props: { password: 'éééééééééééé', email: 'a@b.dev', common: false },
    });

    expect(met(page, 'length')).toBe('true');
  });

  it('rejects the email and its local part, ignoring case and spaces', () => {
    const email = mount(PasswordChecklist, {
      props: { password: ' Maya@Acme.dev ', email: 'maya@acme.dev', common: false },
    });
    const local = mount(PasswordChecklist, {
      props: { password: 'MAYA', email: 'maya@acme.dev', common: false },
    });

    expect(met(email, 'email')).toBe('false');
    expect(met(local, 'email')).toBe('false');
  });

  it('shows the server answer for the common-password rule', () => {
    const common = mount(PasswordChecklist, {
      props: { password: 'password1234', email: 'a@b.dev', common: true },
    });
    const fine = mount(PasswordChecklist, {
      props: { password: 'correct horse battery', email: 'a@b.dev', common: false },
    });

    expect(met(common, 'common')).toBe('false');
    expect(met(fine, 'common')).toBe('true');
  });
});
