<script setup lang="ts">
import { useId } from 'vue';
import Input from '../../components/ui/Input.vue';

defineProps<{ expected: string; error?: string }>();

const typed = defineModel<string>({ default: '' });
const id = useId();
</script>

<template>
  <div class="flex flex-col gap-1">
    <label :for="id" class="text-sm">
      Type <code class="font-mono text-xs" data-test="confirm-key">{{ expected }}</code> to confirm
    </label>
    <Input
      :id="id"
      v-model="typed"
      mono
      autocomplete="off"
      :invalid="Boolean(error)"
      data-test="confirm-input"
    />
    <p v-if="error" class="text-danger-text text-xs">{{ error }}</p>
  </div>
</template>
