<script setup lang="ts" generic="Row extends Record<string, unknown>">
import { PhCaretDown, PhCaretUp } from '@phosphor-icons/vue';
import Skeleton from './Skeleton.vue';

export interface Column {
  key: string;
  label: string;
  sortable?: boolean;
  align?: 'left' | 'right';
  mono?: boolean;
}

export interface Sort {
  key: string;
  direction: 'asc' | 'desc';
}

const props = withDefaults(
  defineProps<{
    columns: Column[];
    rows: Row[];
    rowKey: string;
    loading?: boolean;
    sort?: Sort | null;
    caption: string;
  }>(),
  { loading: false, sort: null },
);

const emit = defineEmits<{ sort: [Sort] }>();

function ariaSort(column: Column): 'ascending' | 'descending' | 'none' | undefined {
  if (!column.sortable) return undefined;
  if (props.sort?.key !== column.key) return 'none';
  return props.sort.direction === 'asc' ? 'ascending' : 'descending';
}

function toggle(column: Column): void {
  const direction =
    props.sort?.key === column.key && props.sort.direction === 'asc' ? 'desc' : 'asc';
  emit('sort', { key: column.key, direction });
}
</script>

<template>
  <div class="border-divider overflow-x-auto rounded-lg border" data-test="data-table">
    <table class="w-full border-collapse text-sm">
      <caption class="sr-only">
        {{
          caption
        }}
      </caption>
      <thead>
        <tr class="border-divider text-muted border-b">
          <th
            v-for="column in columns"
            :key="column.key"
            scope="col"
            :aria-sort="ariaSort(column)"
            :class="[
              'px-4 py-2.5 font-medium',
              column.align === 'right' ? 'text-right' : 'text-left',
            ]"
          >
            <button
              v-if="column.sortable"
              type="button"
              class="inline-flex items-center gap-1"
              :data-test="`sort-${column.key}`"
              @click="toggle(column)"
            >
              {{ column.label }}
              <PhCaretUp v-if="sort?.key === column.key && sort.direction === 'asc'" :size="12" />
              <PhCaretDown v-else-if="sort?.key === column.key" :size="12" />
            </button>
            <template v-else>{{ column.label }}</template>
          </th>
          <th v-if="$slots.actions" scope="col" class="w-12 px-4 py-2.5">
            <span class="sr-only">Actions</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <template v-if="loading">
          <tr v-for="n in 3" :key="n" class="border-divider border-b" data-test="loading-row">
            <td v-for="column in columns" :key="column.key" class="px-4 py-3"><Skeleton /></td>
            <td v-if="$slots.actions" class="px-4 py-3" />
          </tr>
        </template>
        <tr v-else-if="rows.length === 0">
          <td
            :colspan="columns.length + ($slots.actions ? 1 : 0)"
            class="px-4 py-8 text-center"
            data-test="empty-row"
          >
            <slot name="empty"><span class="text-muted">Nothing here yet.</span></slot>
          </td>
        </tr>
        <template v-else>
          <tr
            v-for="row in rows"
            :key="String(row[rowKey])"
            class="border-divider hover:bg-hover border-b last:border-b-0"
            data-test="data-row"
          >
            <td
              v-for="column in columns"
              :key="column.key"
              :class="[
                'px-4 py-3',
                column.align === 'right' ? 'tnum text-right' : '',
                column.mono ? 'font-mono text-xs' : '',
              ]"
            >
              <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]">{{
                row[column.key]
              }}</slot>
            </td>
            <td v-if="$slots.actions" class="px-4 py-3 text-right">
              <slot name="actions" :row="row" />
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
