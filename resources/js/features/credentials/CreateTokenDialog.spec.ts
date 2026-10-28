import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import CreateTokenDialog from './CreateTokenDialog.vue';

function render(
  props: Partial<{ canDraft: boolean; canPublish: boolean; hasPassword: boolean }> = {},
) {
  return mount(CreateTokenDialog, {
    attachTo: document.body,
    props: {
      open: true,
      action: '/x/tokens',
      projectName: 'Storefront',
      canDraft: true,
      canPublish: true,
      hasPassword: true,
      ...props,
    },
  });
}

const select = () =>
  document.body.querySelector<HTMLSelectElement>(
    'select[data-test="token-access"], [data-test="token-access"] select, [data-test="token-access"]',
  );

describe('CreateTokenDialog', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('turns off the access levels the role cannot have', async () => {
    render({ canDraft: false, canPublish: false });
    await flushPromises();
    const options = [...(select()?.querySelectorAll('option') ?? [])].map((option) => [
      option.textContent,
      option.disabled,
    ]);
    expect(options).toEqual([
      ['Read only', false],
      ['Read and edit drafts', true],
      ['Read, edit and publish', true],
    ]);
  });

  it('offers every level to a role that can publish', async () => {
    render();
    await flushPromises();
    expect(
      [...(select()?.querySelectorAll('option') ?? [])].every((option) => !option.disabled),
    ).toBe(true);
  });

  it('asks for a password first when the person has none, and keeps Create off without name and password', async () => {
    render({ hasPassword: false });
    await flushPromises();
    expect(document.body.querySelector('[data-test="token-needs-password"]')).not.toBeNull();
    expect(
      document.body.querySelector<HTMLButtonElement>('[data-test="token-create"]')!.disabled,
    ).toBe(true);
  });

  it('says what a token can never do', async () => {
    render();
    await flushPromises();
    expect(document.body.textContent).toContain(
      'A token can never do more than your role allows. Protected environments still need a review.',
    );
  });
});
