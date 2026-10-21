<script setup lang="ts">
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui';
import { onMounted, watch } from 'vue';

export interface TabItem {
  value: string;
  label: string;
  count?: number;
}

const props = withDefaults(defineProps<{ tabs: TabItem[]; label: string; syncUrl?: boolean }>(), {
  syncUrl: false,
});

const model = defineModel<string>({ default: '' });

onMounted(() => {
  if (!props.syncUrl) return;
  const fromUrl = new URLSearchParams(window.location.search).get('tab');
  if (fromUrl && props.tabs.some((tab) => tab.value === fromUrl)) model.value = fromUrl;
});

watch(model, (value) => {
  if (!props.syncUrl) return;
  const url = new URL(window.location.href);
  url.searchParams.set('tab', value);
  window.history.replaceState(window.history.state, '', url);
});
</script>

<template>
  <TabsRoot v-model="model" data-test="tabs">
    <TabsList :aria-label="label" class="border-divider flex gap-4 border-b">
      <TabsTrigger
        v-for="tab in tabs"
        :key="tab.value"
        :value="tab.value"
        class="data-[state=active]:border-primary data-[state=active]:text-text text-muted -mb-px h-9 border-b-2 border-transparent text-sm font-medium"
        :data-test="`tab-${tab.value}`"
      >
        {{ tab.label }}
        <span v-if="tab.count !== undefined" class="tnum text-muted ml-1 text-xs">{{
          tab.count
        }}</span>
      </TabsTrigger>
    </TabsList>
    <TabsContent v-for="tab in tabs" :key="tab.value" :value="tab.value" class="pt-4">
      <slot :name="tab.value" />
    </TabsContent>
  </TabsRoot>
</template>
