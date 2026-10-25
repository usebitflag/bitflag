<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';

const props = withDefaults(defineProps<{ length?: number; invalid?: boolean; label?: string }>(), {
  length: 6,
  invalid: false,
  label: 'Authentication code',
});
const emit = defineEmits<{ complete: [code: string] }>();
const model = defineModel<string>({ default: '' });

const boxes = ref<HTMLInputElement[]>([]);
const digits = computed(() => Array.from({ length: props.length }, (_, i) => model.value[i] ?? ''));

function focusBox(index: number): void {
  void nextTick(() => boxes.value[Math.min(Math.max(index, 0), props.length - 1)]?.focus());
}

function set(value: string): void {
  model.value = value.replace(/\D/g, '').slice(0, props.length);
  if (model.value.length === props.length) emit('complete', model.value);
}

function onInput(index: number, event: Event): void {
  const typed = (event.target as HTMLInputElement).value.replace(/\D/g, '');
  const next = model.value.slice(0, index) + typed + model.value.slice(index + 1);
  set(next);
  (event.target as HTMLInputElement).value = model.value[index] ?? '';
  focusBox(Math.min(index + Math.max(typed.length, 1), props.length - 1));
}

function onKeydown(index: number, event: KeyboardEvent): void {
  if (event.key === 'Backspace' && !digits.value[index]) {
    set(model.value.slice(0, Math.max(index - 1, 0)));
    focusBox(index - 1);
  } else if (event.key === 'ArrowLeft') {
    focusBox(index - 1);
  } else if (event.key === 'ArrowRight') {
    focusBox(index + 1);
  }
}

function onPaste(event: ClipboardEvent): void {
  const pasted = event.clipboardData?.getData('text') ?? '';
  if (!/\d/.test(pasted)) return;
  event.preventDefault();
  set(pasted);
  focusBox(model.value.length);
}
</script>

<template>
  <div role="group" :aria-label="label" class="flex gap-2" data-test="otp-input" @paste="onPaste">
    <input
      v-for="(digit, index) in digits"
      :key="index"
      :ref="(el) => (boxes[index] = el as HTMLInputElement)"
      :value="digit"
      type="text"
      inputmode="numeric"
      autocomplete="one-time-code"
      maxlength="6"
      :aria-label="`Digit ${index + 1} of ${length}`"
      :aria-invalid="invalid ? 'true' : undefined"
      :class="[
        'bg-surface h-11 w-10 rounded-md border text-center font-mono text-lg',
        invalid ? 'border-danger' : 'border-control-border',
      ]"
      :data-test="`otp-digit-${index}`"
      @input="onInput(index, $event)"
      @keydown="onKeydown(index, $event)"
      @focus="($event.target as HTMLInputElement).select()"
    />
  </div>
</template>
