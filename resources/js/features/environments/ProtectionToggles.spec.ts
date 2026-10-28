import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ProtectionToggles from './ProtectionToggles.vue';

function render(props: {
  protected: boolean;
  requireApproval?: boolean;
  approvalsAvailable?: boolean;
}) {
  return mount(ProtectionToggles, {
    props: {
      disabled: false,
      approvalsAvailable: true,
      requireApproval: false,
      ...props,
    },
  });
}

function approval(wrapper: ReturnType<typeof render>) {
  return wrapper.find('[data-test="protection-approval"] [data-test="switch-root"]');
}

describe('ProtectionToggles', () => {
  it('turns the reviewer switch off until the environment is protected', () => {
    const open = render({ protected: false });
    expect(approval(open).attributes('disabled')).toBeDefined();
    expect(open.text()).toContain('Turn on protection to require a reviewer.');

    const protectedEnv = render({ protected: true });
    expect(approval(protectedEnv).attributes('disabled')).toBeUndefined();
  });

  it('says required approvals are on Pro when the plan does not include them', () => {
    const wrapper = render({ protected: true, approvalsAvailable: false });
    expect(approval(wrapper).attributes('disabled')).toBeDefined();
    expect(wrapper.get('[data-test="approvals-pro"]').text()).toBe(
      'Required approvals are on Pro. Upgrade to turn this on.',
    );
  });

  it('disables both switches when the form is read-only', () => {
    const wrapper = mount(ProtectionToggles, {
      props: { disabled: true, approvalsAvailable: true, protected: true, requireApproval: false },
    });
    expect(
      wrapper
        .find('[data-test="protection-protected"] [data-test="switch-root"]')
        .attributes('disabled'),
    ).toBeDefined();
    expect(
      approval(wrapper as unknown as ReturnType<typeof render>).attributes('disabled'),
    ).toBeDefined();
  });
});
