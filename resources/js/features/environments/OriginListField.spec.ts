import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import OriginListField from './OriginListField.vue';

function render(origins: string[] = [], disabled = false) {
  return mount(OriginListField, {
    props: {
      modelValue: origins,
      disabled,
      'onUpdate:modelValue': (value: string[]) => wrapperValue(value),
    },
  });
}

let latest: string[] = [];
function wrapperValue(value: string[]): void {
  latest = value;
}

describe('OriginListField', () => {
  it('adds a normalized origin on Enter', async () => {
    latest = [];
    const wrapper = render();
    const input = wrapper.get('[data-test="origin-input"] input');

    await input.setValue('HTTPS://App.example.com/');
    await input.trigger('keydown', { key: 'Enter' });

    expect(latest).toEqual(['https://app.example.com']);
  });

  it('shows the error under the field and keeps the list when the origin is not usable', async () => {
    latest = [];
    const wrapper = render(['https://a.example.com']);
    const input = wrapper.get('[data-test="origin-input"] input');

    await input.setValue('https://a.example.com');
    await input.trigger('keydown', { key: 'Enter' });
    expect(wrapper.get('[data-test="origin-error"]').text()).toBe('Already in the list');

    await input.setValue('http://example.com');
    await input.trigger('keydown', { key: 'Enter' });
    expect(wrapper.get('[data-test="origin-error"]').text()).toBe(
      'Use https. Plain http is only for localhost.',
    );
    expect(latest).toEqual([]);
  });

  it('says every browser request is refused when the list is empty', () => {
    expect(render().get('[data-test="origins-empty"]').text()).toContain('refused');
  });

  it('has no input or remove buttons when read-only', () => {
    const wrapper = render(['https://a.example.com'], true);
    expect(wrapper.find('[data-test="origin-input"]').exists()).toBe(false);
    expect(wrapper.find('button').exists()).toBe(false);
  });
});
