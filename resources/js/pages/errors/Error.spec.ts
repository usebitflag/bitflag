import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Error from './Error.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));

const props: { status: number; title: string; message: string; requestId: string | null } = {
  status: 500,
  title: 'Something went wrong',
  message: 'The error has been recorded.',
  requestId: 'req_01j9x3k7q2m5n8p0r4s6t8v0wy',
};

const mountPage = (overrides: Partial<typeof props> = {}) =>
  mount(Error, { props: { ...props, ...overrides } });

describe('error page', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('shows the status, title, sentence and request ID', () => {
    const page = mountPage();
    expect(page.get('[data-test="error-status"]').text()).toBe('500');
    expect(page.get('[data-test="error-title"]').text()).toBe('Something went wrong');
    expect(page.get('[data-test="error-message"]').text()).toBe('The error has been recorded.');
    expect(page.get('[data-test="error-request-id"]').text()).toBe(props.requestId);
  });

  it('links back to the start', () => {
    expect(mountPage().get('[data-test="error-home"]').attributes('href')).toBe('/');
  });

  it('copies the request ID', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText } });
    const page = mountPage();
    await page.get('[data-test="error-copy"]').trigger('click');
    expect(writeText).toHaveBeenCalledWith(props.requestId);
  });

  it('omits the request ID block when there is none', () => {
    expect(mountPage({ requestId: null }).find('[data-test="error-request-id"]').exists()).toBe(
      false,
    );
  });
});
