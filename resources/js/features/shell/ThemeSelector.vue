<script setup lang="ts">
import { computed } from 'vue';
import SegmentedControl from '../../components/ui/SegmentedControl.vue';
import { parsePreference, useTheme } from '../../composables/useTheme';

const { preference, setPreference } = useTheme();

const options = [
  { value: 'light', label: 'Light' },
  { value: 'dark', label: 'Dark' },
  { value: 'system', label: 'System' },
];

const model = computed({
  get: () => preference.value,
  set: (value: string) => setPreference(parsePreference(value)),
});
</script>

<template>
  <SegmentedControl v-model="model" :options="options" label="Theme" />
</template>
