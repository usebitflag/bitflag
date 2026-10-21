<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
  status: number;
  title: string;
  message: string;
  requestId: string | null;
}>();

const copied = ref(false);

async function copyRequestId(): Promise<void> {
  if (!props.requestId) return;
  try {
    await navigator.clipboard.writeText(props.requestId);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
  } catch {
    // Clipboard access can be blocked; the ID stays visible to copy by hand.
  }
}
</script>

<template>
  <Head :title="title" />
  <main class="bg-bg text-text grid min-h-screen place-items-center p-6" data-test="error-page">
    <div class="bg-surface border-divider w-full max-w-md rounded-lg border p-8">
      <p class="text-muted tnum text-sm font-medium" data-test="error-status">{{ status }}</p>
      <h1 class="font-heading mt-1 text-2xl" data-test="error-title">{{ title }}</h1>
      <p class="text-muted mt-2 text-sm" data-test="error-message">{{ message }}</p>

      <div v-if="requestId" class="border-divider mt-6 flex items-center gap-2 border-t pt-4">
        <span class="text-muted text-xs">Request ID</span>
        <code class="font-mono text-xs" data-test="error-request-id">{{ requestId }}</code>
        <button
          type="button"
          class="border-control-border text-text ml-auto h-8 rounded-md border px-3 text-xs"
          data-test="error-copy"
          @click="copyRequestId"
        >
          {{ copied ? 'Copied' : 'Copy' }}
        </button>
      </div>

      <a
        href="/"
        class="bg-primary text-primary-foreground mt-6 inline-flex h-[34px] items-center rounded-md px-3 text-sm font-medium"
        data-test="error-home"
      >
        Go to BitFlag
      </a>
    </div>
  </main>
</template>
