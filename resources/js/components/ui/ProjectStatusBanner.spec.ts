import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import ProjectStatusBanner from './ProjectStatusBanner.vue';

vi.mock('@inertiajs/vue3', () => ({ router: { post: vi.fn() } }));

const base = {
  projectName: 'Storefront',
  deletionDueAt: Date.UTC(2026, 9, 7, 12) / 1000,
  canRestore: true,
  action: '/acme/storefront/production/settings/danger',
};

describe('ProjectStatusBanner', () => {
  it('says when a project will be deleted and that SDKs keep working, with a Restore button for owners', () => {
    const wrapper = mount(ProjectStatusBanner, {
      props: { ...base, status: 'deletion_scheduled' },
    });
    expect(wrapper.text()).toContain('Storefront will be deleted on');
    expect(wrapper.text()).toContain('2026');
    expect(wrapper.text()).toContain('Until then SDKs keep working.');
    expect(wrapper.find('[data-test="project-restore"]').exists()).toBe(true);
  });

  it('offers no restore to people who cannot manage the project', () => {
    const wrapper = mount(ProjectStatusBanner, {
      props: { ...base, status: 'deletion_scheduled', canRestore: false },
    });
    expect(wrapper.find('[data-test="project-restore"]').exists()).toBe(false);
  });

  it('says an archived project is read-only and still serves', () => {
    const wrapper = mount(ProjectStatusBanner, { props: { ...base, status: 'archived' } });
    expect(wrapper.text()).toContain('read-only');
    expect(wrapper.text()).toContain('last published snapshots');
    expect(wrapper.find('[data-test="project-unarchive"]').exists()).toBe(true);
  });

  it('shows nothing for an active project', () => {
    const wrapper = mount(ProjectStatusBanner, { props: { ...base, status: 'active' } });
    expect(wrapper.find('[data-test="project-status-banner"]').exists()).toBe(false);
  });
});
