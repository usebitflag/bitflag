<script setup lang="ts">
import Card from '../../components/ui/Card.vue';
import StatusTag from '../../components/ui/StatusTag.vue';
import Tag from '../../components/ui/Tag.vue';
import type { EnvironmentRow } from './types';

defineProps<{ environment: EnvironmentRow }>();
</script>

<template>
  <div class="grid max-w-[820px] gap-4" data-test="environment-overview">
    <Card title="Published revision">
      <p
        v-if="environment.firstPublishedAt === null"
        class="text-text/70 text-sm"
        data-test="never-published"
      >
        Never published. Nothing published yet. SDKs using this environment's keys get an empty
        snapshot and return your fallback values.
      </p>
      <p v-else class="text-text/70 text-sm">
        First published
        <time :datetime="new Date(environment.firstPublishedAt * 1000).toISOString()">{{
          new Date(environment.firstPublishedAt * 1000).toLocaleDateString()
        }}</time
        >.
      </p>
    </Card>
    <Card title="Protection">
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <Tag v-if="environment.isProtected" variant="accent" data-test="protected-tag"
          >Protected</Tag
        >
        <Tag v-else variant="outline">Not protected</Tag>
        <StatusTag :status="environment.status === 'active' ? 'Active' : 'Archived'" />
        <span class="text-text/62">Policy version {{ environment.policyVersion }}</span>
      </div>
      <p class="text-text/70 mt-2 text-sm">
        {{
          environment.isProtected
            ? 'Publishing needs a reason, and only Owners and Publishers can publish.'
            : 'Anyone who can publish to unprotected environments can publish here.'
        }}
      </p>
    </Card>
  </div>
</template>
