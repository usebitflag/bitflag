import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Avatar from './Avatar.vue';
import Button from './Button.vue';
import EnvLabel from './EnvLabel.vue';
import IconButton from './IconButton.vue';
import Kbd from './Kbd.vue';
import KeyTag from './KeyTag.vue';
import Skeleton from './Skeleton.vue';
import Spinner from './Spinner.vue';
import StatusTag from './StatusTag.vue';
import Tag from './Tag.vue';

describe('Button', () => {
  it('renders each variant and size', () => {
    for (const variant of ['primary', 'secondary', 'ghost', 'destructive'] as const) {
      expect(
        mount(Button, { props: { variant }, slots: { default: 'Go' } }).attributes('data-variant'),
      ).toBe(variant);
    }
    expect(mount(Button, { props: { size: 'sm' } }).classes()).toContain('h-7');
    expect(mount(Button).classes()).toContain('h-[34px]');
  });

  it('is a plain button unless told otherwise', () => {
    expect(mount(Button).attributes('type')).toBe('button');
    expect(mount(Button, { props: { type: 'submit' } }).attributes('type')).toBe('submit');
  });

  it('shows a spinner and blocks clicks while loading', async () => {
    const button = mount(Button, { props: { loading: true }, slots: { default: 'Save' } });

    expect(button.find('[data-test="spinner"]').exists()).toBe(true);
    expect(button.attributes('aria-busy')).toBe('true');
    expect(button.attributes('disabled')).toBeDefined();
  });

  it('can be disabled and can span the width', () => {
    const button = mount(Button, { props: { disabled: true, block: true } });

    expect(button.attributes('disabled')).toBeDefined();
    expect(button.classes()).toContain('w-full');
  });

  it('renders an icon slot before the label', () => {
    const button = mount(Button, { slots: { icon: '<i data-test="ic" />', default: 'Add' } });

    expect(button.find('[data-test="ic"]').exists()).toBe(true);
  });
});

describe('IconButton', () => {
  it('has an accessible name from its label', () => {
    const button = mount(IconButton, { props: { label: 'Close' }, slots: { default: 'x' } });

    expect(button.attributes('aria-label')).toBe('Close');
    expect(button.attributes('title')).toBe('Close');
  });
});

describe('Tag and StatusTag', () => {
  it('renders every tag variant, with an optional dot and mono text', () => {
    for (const variant of [
      'accent',
      'neutral',
      'outline',
      'success',
      'warning',
      'danger',
    ] as const) {
      expect(
        mount(Tag, { props: { variant }, slots: { default: 'x' } }).attributes('data-variant'),
      ).toBe(variant);
    }
    expect(
      mount(Tag, { props: { dot: true } })
        .find('[data-test="tag-dot"]')
        .exists(),
    ).toBe(true);
    expect(mount(Tag, { props: { mono: true } }).classes()).toContain('font-mono');
  });

  it.each([
    ['Active', 'success', false],
    ['Healthy', 'success', true],
    ['Live', 'success', true],
    ['Failing', 'danger', true],
    ['Rotating', 'warning', false],
    ['Unpublished changes', 'warning', false],
    ['Draft', 'outline', false],
    ['Revoked', 'neutral', false],
    ['Archived', 'neutral', false],
    ['Something new', 'neutral', false],
  ])('maps %s to %s (dot: %s)', (status, variant, dot) => {
    const tag = mount(StatusTag, { props: { status } });

    expect(tag.attributes('data-variant')).toBe(variant);
    expect(tag.find('[data-test="tag-dot"]').exists()).toBe(dot);
    expect(tag.text()).toBe(status);
  });

  it('never treats a draft or work in progress as a failure', () => {
    for (const status of ['Draft', 'In review', 'Pending']) {
      expect(mount(StatusTag, { props: { status } }).attributes('data-variant')).not.toBe('danger');
    }
  });
});

describe('EnvLabel, Avatar, Kbd, Spinner, Skeleton', () => {
  it('shows the environment key in monospace', () => {
    const label = mount(EnvLabel, { props: { environmentKey: 'production' } });

    expect(label.find('code').classes()).toContain('font-mono');
    expect(label.text()).toBe('production');
  });

  it('shows initials and a name for assistive technology', () => {
    const avatar = mount(Avatar, { props: { name: 'Maya Santos' } });

    expect(avatar.text()).toBe('MS');
    expect(avatar.attributes('aria-label')).toBe('Maya Santos');
  });

  it('renders keyboard hints, a labeled spinner and a hidden skeleton', () => {
    expect(mount(Kbd, { slots: { default: '⌘K' } }).text()).toBe('⌘K');
    expect(mount(Spinner).attributes('aria-label')).toBe('Loading');
    expect(mount(Skeleton).attributes('aria-hidden')).toBe('true');
  });
});

describe('KeyTag', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('shows the key in monospace and copies it', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const tag = mount(KeyTag, { props: { value: 'new-checkout' } });

    expect(tag.get('[data-test="key-tag-value"]').classes()).toContain('font-mono');
    await tag.get('[data-test="key-tag-copy"]').trigger('click');

    expect(writeText).toHaveBeenCalledWith('new-checkout');
    expect(tag.get('[data-test="key-tag-copy"]').attributes('aria-label')).toBe('Copied');
  });
});
