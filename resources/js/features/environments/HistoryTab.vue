<script setup lang="ts">
import Card from '../../components/ui/Card.vue';
import EmptyState from '../../components/ui/EmptyState.vue';
import type { HistoryEntry } from './types';

defineProps<{ entries: HistoryEntry[] }>();

function describe(action: string): string {
  const [object, verb] = action.split('.');
  return `${object?.replace(/_/g, ' ')} ${verb?.replace(/_/g, ' ')}`;
}
</script>

<template>
  <div class="max-w-[820px]" data-test="environment-history">
    <EmptyState
      v-if="entries.length === 0"
      title="No history yet"
      message="Changes to this environment show up here."
    />
    <Card v-else title="History">
      <ol class="flex flex-col gap-3">
        <li v-for="entry in entries" :key="entry.id" class="text-sm" data-test="history-entry">
          <span class="font-medium">{{ entry.actor }}</span>
          {{ describe(entry.action) }}
          <time
            class="text-text/55 ml-1 text-xs"
            :datetime="new Date(entry.occurredAt * 1000).toISOString()"
            >{{ new Date(entry.occurredAt * 1000).toLocaleString() }}</time
          >
          <p v-if="entry.reason" class="text-text/62 text-xs">{{ entry.reason }}</p>
        </li>
      </ol>
    </Card>
  </div>
</template>
