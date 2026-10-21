<script setup lang="ts">
import { useToast } from '../../composables/useToast';

const { toasts, dismiss, pause, resume } = useToast();

function runAction(id: number, onClick: () => void): void {
  onClick();
  dismiss(id);
}
</script>

<template>
  <div
    class="pointer-events-none fixed right-4 bottom-4 z-50 flex w-80 flex-col gap-2"
    aria-live="polite"
    aria-atomic="false"
    data-test="toast-viewport"
  >
    <div
      v-for="toast in toasts"
      :key="toast.id"
      :class="[
        'bg-popover border-divider shadow-md pointer-events-auto flex items-center gap-3 rounded-md border px-3 py-2.5 text-sm',
        toast.tone === 'accent' ? 'border-primary' : '',
      ]"
      role="status"
      data-test="toast"
      @mouseenter="pause(toast.id)"
      @mouseleave="resume(toast.id)"
      @focusin="pause(toast.id)"
      @focusout="resume(toast.id)"
    >
      <span class="flex-1" data-test="toast-message">{{ toast.message }}</span>
      <button
        v-if="toast.action"
        type="button"
        class="text-primary-ink text-sm font-medium"
        data-test="toast-action"
        @click="runAction(toast.id, toast.action.onClick)"
      >
        {{ toast.action.label }}
      </button>
    </div>
  </div>
</template>
