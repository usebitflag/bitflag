import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DeleteProjectDialog from './DeleteProjectDialog.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));

describe('DeleteProjectDialog', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('keeps Delete disabled until the project key is typed exactly', async () => {
    mount(DeleteProjectDialog, {
      attachTo: document.body,
      props: {
        open: true,
        projectName: 'Storefront',
        projectKey: 'storefront',
        action: '/x/delete',
      },
    });
    await flushPromises();

    const confirm = document.body.querySelector<HTMLButtonElement>(
      '[data-test="project-delete-confirm"]',
    )!;
    const input = document.body.querySelector<HTMLInputElement>(
      '[data-test="confirm-input"] input, input[data-test="confirm-input"]',
    )!;
    expect(confirm.disabled).toBe(true);

    input.value = 'storefro';
    input.dispatchEvent(new Event('input'));
    await flushPromises();
    expect(confirm.disabled).toBe(true);

    input.value = 'storefront';
    input.dispatchEvent(new Event('input'));
    await flushPromises();
    expect(confirm.disabled).toBe(false);
    expect(document.body.textContent).toContain('You can restore it any time before then.');
  });
});
