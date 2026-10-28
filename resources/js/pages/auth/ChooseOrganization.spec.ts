import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

const form = reactive({ organization: '', remember: false, processing: false, post: vi.fn() });

vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null }, useForm: () => form }));

import ChooseOrganization from './ChooseOrganization.vue';

const organizations = [
  { id: 'org_1', slug: 'acme', name: 'Acme', role: 'owner' as const, projectCount: 2 },
  { id: 'org_2', slug: 'globex', name: 'Globex', role: 'member' as const, projectCount: 1 },
];

describe('choose organization page', () => {
  it('shows each organization with the role and project count', () => {
    const page = mount(ChooseOrganization, { props: { organizations, remember: false } });

    expect(page.get('[data-test="choose-acme"]').text()).toContain('Org Owner · 2 projects');
    expect(page.get('[data-test="choose-globex"]').text()).toContain('Member · 1 project');
  });

  it('posts the chosen organization with the remember choice', async () => {
    const page = mount(ChooseOrganization, { props: { organizations, remember: false } });

    await page.get('[data-test="choose-remember"] input').setValue(true);
    await page.get('[data-test="choose-globex"]').trigger('click');

    expect(form.organization).toBe('globex');
    expect(form.remember).toBe(true);
    expect(form.post).toHaveBeenCalledWith('/organizations/choose');
  });
});
