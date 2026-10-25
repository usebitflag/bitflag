import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';
import Checkbox from './Checkbox.vue';
import Field from './Field.vue';
import Input from './Input.vue';
import RadioGroup from './RadioGroup.vue';
import SegmentedControl from './SegmentedControl.vue';
import Select from './Select.vue';
import Switch from './Switch.vue';
import Textarea from './Textarea.vue';

describe('Field', () => {
  it('ties the label, help and error to the control', () => {
    const field = mount(Field, {
      props: { label: 'Flag key', help: 'Lowercase letters and dashes.', error: 'Already in use.' },
      slots: {
        default: (slot: { id: string; describedBy?: string; invalid: boolean }) =>
          h(Input, { id: slot.id, describedBy: slot.describedBy, invalid: slot.invalid }),
      },
    });

    const input = field.get('input');
    const describedBy = input.attributes('aria-describedby') ?? '';

    expect(field.get('label').attributes('for')).toBe(input.attributes('id'));
    expect(describedBy.split(' ')).toHaveLength(2);
    expect(field.get('[data-test="field-error"]').attributes('id')).toBe(describedBy.split(' ')[0]);
    expect(input.attributes('aria-invalid')).toBe('true');
  });

  it('shows no description when there is neither help nor an error', () => {
    const field = mount(Field, {
      props: { label: 'Name' },
      slots: {
        default: (slot: { id: string; describedBy?: string }) =>
          h(Input, { id: slot.id, describedBy: slot.describedBy }),
      },
    });

    expect(field.get('input').attributes('aria-describedby')).toBeUndefined();
  });
});

describe('Input, Textarea and Select', () => {
  it('updates its model and supports prefix, suffix, mono and invalid', async () => {
    const input = mount(Input, {
      props: {
        modelValue: '',
        'onUpdate:modelValue': (v: string) => input.setProps({ modelValue: v }),
        mono: true,
        invalid: true,
      },
      slots: { prefix: '$', suffix: 'ms' },
    });

    await input.get('input').setValue('abc');

    expect(input.props('modelValue')).toBe('abc');
    expect(input.get('input').classes()).toContain('font-mono');
    expect(input.get('input').attributes('aria-invalid')).toBe('true');
    expect(input.text()).toContain('$');
    expect(input.text()).toContain('ms');
  });

  it('marks a textarea and a select invalid', () => {
    expect(mount(Textarea, { props: { invalid: true } }).attributes('aria-invalid')).toBe('true');
    const select = mount(Select, {
      props: { options: [{ value: 'a', label: 'A' }], invalid: true, mono: true },
    });
    expect(select.get('select').attributes('aria-invalid')).toBe('true');
    expect(select.findAll('option')).toHaveLength(1);
  });
});

describe('choice controls', () => {
  it('shows help text under a checkbox and a switch', () => {
    const help = 'Client-visible by default · Leaving this off is safer.';

    expect(mount(Checkbox, { props: { label: 'Client-visible', help } }).text()).toContain(help);
    expect(mount(Switch, { props: { label: 'Client-visible', help } }).text()).toContain(help);
  });

  it('toggles a switch from the keyboard', async () => {
    const toggle = mount(Switch, {
      props: {
        label: 'Enabled',
        modelValue: false,
        'onUpdate:modelValue': (v: boolean) => toggle.setProps({ modelValue: v }),
      },
    });

    await toggle.get('[data-test="switch-root"]').trigger('click');

    expect(toggle.props('modelValue')).toBe(true);
    expect(toggle.get('[data-test="switch-root"]').attributes('aria-checked')).toBe('true');
  });

  it('selects one option in a radio group', async () => {
    const group = mount(RadioGroup, {
      props: {
        label: 'Plan',
        modelValue: 'free',
        'onUpdate:modelValue': (v: string) => group.setProps({ modelValue: v }),
        options: [
          { value: 'free', label: 'Free' },
          { value: 'pro', label: 'Pro' },
        ],
      },
    });

    await group.findAll('[role="radio"]')[1]?.trigger('click');

    expect(group.props('modelValue')).toBe('pro');
  });

  it('keeps exactly one segment selected', async () => {
    const control = mount(SegmentedControl, {
      props: {
        label: 'Theme',
        modelValue: 'light',
        'onUpdate:modelValue': (v: string) => control.setProps({ modelValue: v }),
        options: [
          { value: 'light', label: 'Light' },
          { value: 'dark', label: 'Dark' },
        ],
      },
    });

    await control.findAll('button')[1]?.trigger('click');
    expect(control.props('modelValue')).toBe('dark');

    await control.findAll('button')[1]?.trigger('click');
    expect(control.props('modelValue')).toBe('dark');
  });
});
