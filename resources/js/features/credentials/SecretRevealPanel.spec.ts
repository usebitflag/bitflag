import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import SecretRevealPanel from './SecretRevealPanel.vue';
import { useSecretReveal } from '../../composables/useSecretReveal';

const secret = 'bf_srv_dev_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

function render() {
  return mount(SecretRevealPanel, {
    props: {
      secret,
      variable: 'BITFLAG_SERVER_KEY',
      environmentName: 'Staging',
      acknowledged: false,
    },
  });
}

describe('SecretRevealPanel', () => {
  it('keeps Done disabled until the key is acknowledged', async () => {
    const wrapper = render();
    expect(wrapper.get('[data-test="secret-done"]').attributes('disabled')).toBeDefined();

    await wrapper.setProps({ acknowledged: true });
    expect(wrapper.get('[data-test="secret-done"]').attributes('disabled')).toBeUndefined();

    await wrapper.get('[data-test="secret-done"]').trigger('click');
    expect(wrapper.emitted('done')).toHaveLength(1);
  });

  it('shows the key and the environment variable line', () => {
    const wrapper = render();
    expect(wrapper.get('[data-test="secret-value"]').text()).toBe(secret);
    expect(wrapper.text()).toContain(`BITFLAG_SERVER_KEY=${secret}`);
    expect(wrapper.text()).toContain("You won't be able to see it again");
  });
});

describe('useSecretReveal', () => {
  it('clears the secret when cleared and when the component unmounts', () => {
    let reveal!: ReturnType<typeof useSecretReveal>;
    const wrapper = mount(
      defineComponent({
        setup() {
          reveal = useSecretReveal();
          return () => h('div');
        },
      }),
    );

    reveal.show('abc');
    reveal.acknowledged.value = true;
    expect(reveal.secret.value).toBe('abc');

    reveal.clear();
    expect(reveal.secret.value).toBeNull();
    expect(reveal.acknowledged.value).toBe(false);

    reveal.show('def');
    wrapper.unmount();
    expect(reveal.secret.value).toBeNull();
  });

  it('is not stored anywhere the page could read it back', () => {
    const holder = ref<string | null>(null);
    holder.value = secret;
    expect(JSON.stringify(Object.entries(window.localStorage))).not.toContain(secret);
    expect(JSON.stringify(Object.entries(window.sessionStorage))).not.toContain(secret);
  });
});
