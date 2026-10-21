import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import ConfirmDialog from './ConfirmDialog.vue';
import Dialog from './Dialog.vue';
import DropdownMenu from './DropdownMenu.vue';

const wrappers: Array<{ unmount: () => void }> = [];
const attach = <T extends { unmount: () => void }>(wrapper: T): T => {
  wrappers.push(wrapper);
  return wrapper;
};

afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
  document.body.innerHTML = '';
});

const settle = async () => {
  await nextTick();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await nextTick();
};

const press = async (target: Element | Document, key: string) => {
  target.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
  await nextTick();
  await new Promise((resolve) => setTimeout(resolve, 0));
};

describe('Dialog', () => {
  it('shows its title and description and closes with Escape', async () => {
    const dialog = attach(
      mount(Dialog, {
        props: {
          open: true,
          title: 'Archive flag',
          description: 'It stops serving.',
          'onUpdate:open': (v: boolean) => dialog.setProps({ open: v }),
        },
        slots: { default: 'Body' },
        attachTo: document.body,
      }),
    );
    await settle();

    expect(document.body.querySelector('[data-test="dialog"]')?.textContent).toContain(
      'Archive flag',
    );
    expect(
      document.body.querySelector('[role="dialog"]')?.getAttribute('aria-describedby'),
    ).toBeTruthy();

    await press(document.body.querySelector('[data-test="dialog"]') as Element, 'Escape');

    expect(dialog.props('open')).toBe(false);
  });
});

describe('ConfirmDialog', () => {
  const open = (props: Record<string, unknown> = {}) =>
    attach(
      mount(ConfirmDialog, {
        props: { open: true, title: 'Delete flag', objectName: 'new-checkout', ...props },
        attachTo: document.body,
      }),
    );

  const submit = () =>
    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-submit"]');

  it('names the object it will change', async () => {
    open();
    await settle();

    expect(document.body.querySelector('[data-test="confirm-object"]')?.textContent).toContain(
      'new-checkout',
    );
  });

  it('keeps the button disabled until the typed key matches exactly', async () => {
    open({ confirmText: 'new-checkout', destructive: true });
    await settle();
    const input = document.body.querySelector<HTMLInputElement>(
      '[data-test="confirm-input"]',
    ) as HTMLInputElement;

    expect(submit()?.disabled).toBe(true);

    for (const value of ['new-checkou', 'New-Checkout', 'new-checkout ']) {
      input.value = value;
      input.dispatchEvent(new Event('input'));
      await nextTick();
      expect(submit()?.disabled).toBe(true);
    }

    input.value = 'new-checkout';
    input.dispatchEvent(new Event('input'));
    await nextTick();
    expect(submit()?.disabled).toBe(false);
  });

  it('confirms without typing when no key is required, and says which action is destructive', async () => {
    const dialog = open({ destructive: true });
    await settle();

    expect(submit()?.disabled).toBe(false);
    expect(submit()?.getAttribute('data-variant')).toBe('destructive');

    submit()?.click();
    await nextTick();
    expect(dialog.emitted('confirm')).toHaveLength(1);
  });

  it('cancels', async () => {
    const dialog = open();
    await settle();

    document.body.querySelector<HTMLButtonElement>('[data-test="confirm-cancel"]')?.click();
    await nextTick();

    expect(dialog.emitted('cancel')).toHaveLength(1);
  });
});

describe('DropdownMenu', () => {
  const mountMenu = () =>
    attach(
      mount(DropdownMenu, {
        props: {
          label: 'Flag actions',
          items: [
            { key: 'edit', label: 'Edit' },
            { key: 'archive', label: 'Archive', destructive: true },
          ],
        },
        slots: { trigger: '<button type="button" data-test="trigger">Actions</button>' },
        attachTo: document.body,
      }),
    );

  it('opens from the keyboard, renders outside the page content, and lists destructive items below a divider', async () => {
    mountMenu();
    const trigger = document.body.querySelector('[data-test="trigger"]') as HTMLButtonElement;

    trigger.focus();
    await press(trigger, 'Enter');

    const menu = document.body.querySelector('[data-test="menu"]');
    expect(menu).not.toBeNull();
    expect(
      document.body
        .querySelector('[data-test="menu-item-edit"]')
        ?.nextElementSibling?.getAttribute('role'),
    ).toBe('separator');
    expect(document.body.querySelector('[data-test="menu-item-archive"]')?.className).toContain(
      'text-danger-text',
    );
  });

  it('closes with Escape', async () => {
    mountMenu();
    const trigger = document.body.querySelector('[data-test="trigger"]') as HTMLButtonElement;
    trigger.focus();
    await press(trigger, 'Enter');
    expect(document.body.querySelector('[data-test="menu"]')).not.toBeNull();

    await press(document.body.querySelector('[data-test="menu"]') as Element, 'Escape');

    expect(document.body.querySelector('[data-test="menu"]')).toBeNull();
  });
});
