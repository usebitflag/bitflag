<script setup lang="ts">
import {
  DialogContent,
  DialogDescription,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from 'reka-ui';

withDefaults(defineProps<{ title: string; description?: string; dismissable?: boolean }>(), {
  dismissable: true,
});

const open = defineModel<boolean>('open', { default: false });
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="bg-backdrop/45 fixed inset-0 z-40" data-test="dialog-backdrop" />
      <DialogContent
        class="bg-surface border-divider shadow-lg fixed top-1/2 left-1/2 z-50 w-[min(92vw,28rem)] -translate-x-1/2 -translate-y-1/2 rounded-lg border p-6"
        data-test="dialog"
        @escape-key-down="(event) => !dismissable && event.preventDefault()"
        @pointer-down-outside="(event) => !dismissable && event.preventDefault()"
        @interact-outside="(event) => !dismissable && event.preventDefault()"
      >
        <DialogTitle class="font-heading text-lg">{{ title }}</DialogTitle>
        <DialogDescription v-if="description" class="text-muted mt-1 text-sm">{{
          description
        }}</DialogDescription>
        <div class="mt-4"><slot /></div>
        <div v-if="$slots.footer" class="mt-6 flex justify-end gap-2"><slot name="footer" /></div>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
