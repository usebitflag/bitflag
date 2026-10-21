import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Banner from './Banner.vue';
import Breadcrumbs from './Breadcrumbs.vue';
import Card from './Card.vue';
import DataTable from './DataTable.vue';
import EmptyState from './EmptyState.vue';
import InlineNotice from './InlineNotice.vue';
import PageHeader from './PageHeader.vue';
import StatCard from './StatCard.vue';
import Tabs from './Tabs.vue';

const columns = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'count', label: 'Count', align: 'right' as const },
];
const rows = [
  { id: 'a', name: 'Alpha', count: 3 },
  { id: 'b', name: 'Beta', count: 5 },
];

describe('DataTable', () => {
  it('renders a row per record with a caption and column headers', () => {
    const table = mount(DataTable, { props: { columns, rows, rowKey: 'id', caption: 'Flags' } });

    expect(table.findAll('[data-test="data-row"]')).toHaveLength(2);
    expect(table.get('caption').text()).toBe('Flags');
    expect(table.findAll('th')).toHaveLength(2);
  });

  it('announces sort state and asks for the opposite direction on a second click', async () => {
    const table = mount(DataTable, {
      props: {
        columns,
        rows,
        rowKey: 'id',
        caption: 'Flags',
        sort: { key: 'name', direction: 'asc' },
      },
    });

    expect(table.findAll('th')[0]?.attributes('aria-sort')).toBe('ascending');
    expect(table.findAll('th')[1]?.attributes('aria-sort')).toBeUndefined();

    await table.get('[data-test="sort-name"]').trigger('click');
    expect(table.emitted('sort')?.[0]).toEqual([{ key: 'name', direction: 'desc' }]);
  });

  it('shows skeleton rows while loading and the empty slot with no rows', () => {
    const loading = mount(DataTable, {
      props: { columns, rows: [], rowKey: 'id', caption: 'Flags', loading: true },
    });
    expect(loading.findAll('[data-test="loading-row"]')).toHaveLength(3);

    const empty = mount(DataTable, {
      props: { columns, rows: [], rowKey: 'id', caption: 'Flags' },
      slots: { empty: 'No flags yet' },
    });
    expect(empty.get('[data-test="empty-row"]').text()).toBe('No flags yet');
  });

  it('scrolls horizontally on narrow screens and renders a row action slot', () => {
    const table = mount(DataTable, {
      props: { columns, rows, rowKey: 'id', caption: 'Flags' },
      slots: { actions: '<button>More</button>' },
    });

    expect(table.classes()).toContain('overflow-x-auto');
    expect(table.findAll('button').filter((button) => button.text() === 'More')).toHaveLength(2);
  });
});

describe('Tabs', () => {
  it('shows counts in the tab labels', () => {
    const tabs = mount(Tabs, {
      props: {
        label: 'Credentials',
        modelValue: 'keys',
        tabs: [
          { value: 'keys', label: 'Keys', count: 4 },
          { value: 'tokens', label: 'Tokens' },
        ],
      },
      slots: { keys: 'Keys panel', tokens: 'Tokens panel' },
    });

    expect(tabs.get('[data-test="tab-keys"]').text()).toContain('Keys');
    expect(tabs.get('[data-test="tab-keys"]').text()).toContain('4');
    expect(tabs.get('[data-test="tab-tokens"]').text()).toBe('Tokens');
  });
});

describe('content components', () => {
  it('renders a card with kicker, title and meta', () => {
    const card = mount(Card, {
      props: { kicker: 'Rollout', title: 'new-checkout', meta: 'Updated 3 min ago' },
      slots: { default: 'Body' },
    });

    expect(card.text()).toContain('Rollout');
    expect(card.text()).toContain('new-checkout');
    expect(card.text()).toContain('Updated 3 min ago');
  });

  it('renders a stat with tabular figures, or says it is not collected', () => {
    const stat = mount(StatCard, {
      props: { label: 'Evaluations', value: '34.1M', delta: '+15.2%', baseline: 'vs last week' },
    });
    expect(stat.get('[data-test="stat-value"]').classes()).toContain('tnum');

    const missing = mount(StatCard, {
      props: { label: 'Unique users', value: '0', unavailable: true },
    });
    expect(missing.get('[data-test="stat-unavailable"]').text()).toBe('Not collected');
    expect(missing.find('[data-test="stat-value"]').exists()).toBe(false);
  });

  it('shows banners and notices with a text label, not color alone', () => {
    expect(
      mount(Banner, {
        props: { tone: 'attention', label: 'Heads up' },
        slots: { default: 'Read-only' },
      }).text(),
    ).toContain('Heads up');
    const notice = mount(InlineNotice, {
      props: { tone: 'danger', title: 'Publish failed' },
      slots: { default: 'Try again.' },
    });
    expect(notice.attributes('role')).toBe('alert');
    expect(notice.get('[data-test="notice-title"]').text()).toBe('Publish failed');
  });

  it('offers actions in an empty state', () => {
    const empty = mount(EmptyState, {
      props: { title: 'No keys yet', message: 'Create a key to connect an SDK.' },
      slots: { actions: '<button>Create key</button>' },
    });

    expect(empty.text()).toContain('No keys yet');
    expect(empty.findAll('button')).toHaveLength(1);
  });

  it('marks the current page in breadcrumbs', () => {
    const crumbs = mount(Breadcrumbs, {
      props: {
        items: [
          { label: 'Acme', href: '/acme' },
          { label: 'Storefront', href: '/acme/storefront' },
          { label: 'Overview' },
        ],
      },
    });

    expect(crumbs.get('nav').attributes('aria-label')).toBe('Breadcrumb');
    expect(crumbs.findAll('a')).toHaveLength(2);
    expect(crumbs.get('[aria-current="page"]').text()).toBe('Overview');
  });

  it('shows a page header with title, sentence, key, tags and actions', () => {
    const header = mount(PageHeader, {
      props: { title: 'new-checkout', description: 'Checkout redesign' },
      slots: {
        tags: '<span>Live</span>',
        key: '<code>new-checkout</code>',
        actions: '<button>Edit</button>',
      },
    });

    expect(header.get('[data-test="page-title"]').text()).toBe('new-checkout');
    expect(header.text()).toContain('Checkout redesign');
    expect(header.text()).toContain('Live');
    expect(header.findAll('button')).toHaveLength(1);
  });
});
