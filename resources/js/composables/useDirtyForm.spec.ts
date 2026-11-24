import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive } from 'vue';
import { mount } from '@vue/test-utils';
import { hasUnsavedChanges, resetNavigationGuardForTests } from './useNavigationGuard';
import { useDirtyForm } from './useDirtyForm';

vi.mock('@inertiajs/vue3', () => ({ router: { on: vi.fn() } }));

function mountWith(form: { isDirty: boolean; reset: () => void }) {
  let api!: ReturnType<typeof useDirtyForm>;
  const wrapper = mount(
    defineComponent({
      setup() {
        api = useDirtyForm(form);
        return () => h('div');
      },
    }),
  );
  return { wrapper, api };
}

describe('useDirtyForm', () => {
  afterEach(resetNavigationGuardForTests);

  it('blocks leaving only while the form has unsaved changes', () => {
    const form = reactive({ isDirty: false, reset: vi.fn() });
    const { api } = mountWith(form);

    expect(hasUnsavedChanges()).toBe(false);
    expect(api.dirty.value).toBe(false);

    form.isDirty = true;
    expect(hasUnsavedChanges()).toBe(true);
    expect(api.dirty.value).toBe(true);
  });

  it('releases the guard when the page unmounts', () => {
    const form = reactive({ isDirty: true, reset: vi.fn() });
    const { wrapper } = mountWith(form);

    expect(hasUnsavedChanges()).toBe(true);
    wrapper.unmount();
    expect(hasUnsavedChanges()).toBe(false);
  });

  it('resets the form', () => {
    const form = reactive({ isDirty: true, reset: vi.fn() });
    const { api } = mountWith(form);

    api.reset();
    expect(form.reset).toHaveBeenCalledOnce();
  });
});
