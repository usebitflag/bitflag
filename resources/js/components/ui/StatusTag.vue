<script setup lang="ts">
import { computed } from 'vue';
import Tag, { type TagVariant } from './Tag.vue';

/**
 * Maps a domain status to a labeled semantic variant. Healthy runtime states may be success, real failures are danger,
 * states that need attention are warning, and drafts or work in progress are not failures. Ended or disabled states
 * stay neutral. Runtime states carry a dot so the meaning never rests on color alone.
 */
const MAP: Record<string, { variant: TagVariant; dot?: boolean }> = {
  Active: { variant: 'success' },
  Enabled: { variant: 'success' },
  Published: { variant: 'success' },
  Approved: { variant: 'success' },
  Healthy: { variant: 'success', dot: true },
  Live: { variant: 'success', dot: true },
  Connected: { variant: 'success', dot: true },
  Failing: { variant: 'danger', dot: true },
  Failed: { variant: 'danger' },
  Error: { variant: 'danger', dot: true },
  Rotating: { variant: 'warning' },
  Degraded: { variant: 'warning', dot: true },
  'Unpublished changes': { variant: 'warning' },
  'Needs review': { variant: 'warning' },
  Draft: { variant: 'outline' },
  'In review': { variant: 'outline' },
  Pending: { variant: 'outline' },
  Revoked: { variant: 'neutral' },
  Archived: { variant: 'neutral' },
  Disabled: { variant: 'neutral' },
  Deprecated: { variant: 'neutral' },
  Expired: { variant: 'neutral' },
};

const props = defineProps<{ status: string }>();

const mapped = computed(() => MAP[props.status] ?? { variant: 'neutral' as TagVariant });
</script>

<template>
  <Tag :variant="mapped.variant" :dot="mapped.dot === true" data-test="status-tag">{{
    status
  }}</Tag>
</template>
