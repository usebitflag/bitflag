<script setup lang="ts">
const props = defineProps<{ colors: string[]; disabled?: boolean }>();

const model = defineModel<string>({ required: true });

// The swatch is only a label: the name is always shown beside it.
const swatch: Record<string, string> = {
  slate: 'bg-muted',
  lilac: 'bg-primary-soft',
  violet: 'bg-primary',
  indigo: 'bg-selection',
  ink: 'bg-text',
};

function label(color: string): string {
  return color.charAt(0).toUpperCase() + color.slice(1);
}
</script>

<template>
  <div
    role="radiogroup"
    aria-label="Label color"
    class="flex flex-wrap gap-2"
    data-test="color-picker"
  >
    <label
      v-for="color in props.colors"
      :key="color"
      :class="[
        'border-control-border inline-flex h-8 cursor-pointer items-center gap-2 rounded-md border px-2.5 text-sm',
        model === color ? 'border-primary bg-selection' : 'hover:bg-hover',
        disabled ? 'cursor-not-allowed opacity-60' : '',
      ]"
    >
      <input
        v-model="model"
        type="radio"
        name="environment-color"
        class="sr-only"
        :value="color"
        :disabled="disabled"
        :data-test="`color-${color}`"
      />
      <span
        :class="['border-divider h-3 w-3 rounded-full border', swatch[color] ?? 'bg-muted']"
        aria-hidden="true"
      />
      {{ label(color) }}
    </label>
  </div>
</template>
