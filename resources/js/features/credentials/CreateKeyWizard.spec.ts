import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { KEY_KINDS } from './keyKinds';
import CreateKeyWizard from './CreateKeyWizard.vue';

vi.mock('../auth/sendJson', async () => {
  const actual = await vi.importActual<typeof import('../auth/sendJson')>('../auth/sendJson');
  return { ...actual, sendJson: vi.fn() };
});
import { sendJson } from '../auth/sendJson';

function render(allowedOrigins: string[] = []) {
  return mount(CreateKeyWizard, {
    attachTo: document.body,
    props: { open: true, action: '/x/keys', environmentName: 'Staging', allowedOrigins },
  });
}

const body = () => document.body;

describe('CreateKeyWizard', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.mocked(sendJson).mockReset();
  });

  it('offers the three key types with their default scopes', () => {
    expect(KEY_KINDS.map((kind) => [kind.type, kind.defaults])).toEqual([
      ['server', ['snapshot:read']],
      ['browser', ['snapshot:read']],
      ['management', ['flags:read']],
    ]);
  });

  it('needs a name and at least one permission before it creates', async () => {
    render();
    await flushPromises();
    body().querySelector<HTMLButtonElement>('[data-test="key-next"]')!.click();
    await flushPromises();

    const create = body().querySelector<HTMLButtonElement>('[data-test="key-create"]')!;
    expect(create.disabled).toBe(true);

    const label = body().querySelector<HTMLInputElement>(
      '[data-test="key-label"] input, input[data-test="key-label"]',
    )!;
    label.value = 'Checkout';
    label.dispatchEvent(new Event('input'));
    await flushPromises();
    expect(create.disabled).toBe(false);

    const scope = body().querySelector<HTMLInputElement>(
      '[data-test="scope-snapshot:read"] input',
    )!;
    scope.click();
    await flushPromises();
    expect(create.disabled).toBe(true);
  });

  it('lists the allowed origins in the browser key note, or says none are set', async () => {
    const wrapper = render(['https://app.example.com']);
    await flushPromises();
    body().querySelector<HTMLInputElement>('[data-test="key-type-browser"]')!.click();
    await flushPromises();
    body().querySelector<HTMLButtonElement>('[data-test="key-next"]')!.click();
    await flushPromises();
    expect(body().querySelector('[data-test="browser-origins-note"]')!.textContent).toContain(
      'https://app.example.com',
    );

    await wrapper.setProps({ allowedOrigins: [] });
    expect(body().querySelector('[data-test="browser-origins-note"]')!.textContent).toContain(
      'No allowed origins are set yet',
    );
  });
});
