<script setup lang="ts">
import { PhX } from '@phosphor-icons/vue';
import Input from '../../components/ui/Input.vue';
import { useOriginList } from '../../composables/useOriginList';

defineProps<{ disabled: boolean; serverError?: string }>();

const origins = defineModel<string[]>({ required: true });
const { draft, error, full, add, remove } = useOriginList(origins);
</script>

<template>
  <div class="flex flex-col gap-2" data-test="origin-list">
    <ul v-if="origins.length" class="flex flex-wrap gap-1.5" aria-label="Allowed origins">
      <li
        v-for="origin in origins"
        :key="origin"
        class="border-divider bg-hover inline-flex h-7 items-center gap-1.5 rounded-md border pr-1 pl-2.5 font-mono text-xs"
        data-test="origin-chip"
      >
        {{ origin }}
        <button
          v-if="!disabled"
          type="button"
          class="hover:bg-selection grid h-5 w-5 place-items-center rounded"
          :aria-label="`Remove ${origin}`"
          @click="remove(origin)"
        >
          <PhX :size="11" aria-hidden="true" />
        </button>
      </li>
    </ul>
    <p v-else class="text-text/62 text-sm" data-test="origins-empty">
      No origins. Every request made with a browser key is refused.
    </p>
    <Input
      v-if="!disabled"
      v-model="draft"
      mono
      :disabled="full"
      :invalid="error !== null || Boolean(serverError)"
      placeholder="https://app.example.com"
      aria-label="Add an origin"
      data-test="origin-input"
      @keydown.enter.prevent="add"
    />
    <p v-if="error || serverError" class="text-danger-text text-xs" data-test="origin-error">
      {{ error ?? serverError }}
    </p>
    <p v-else-if="!disabled" class="text-text/58 text-xs">Press Enter to add an origin.</p>
  </div>
</template>
