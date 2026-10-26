<script setup lang="ts">
import { PhEye, PhEyeSlash } from '@phosphor-icons/vue';
import { ref } from 'vue';
import Input from '../../components/ui/Input.vue';

defineProps<{
  id?: string;
  invalid?: boolean;
  describedBy?: string;
  placeholder?: string;
  autocomplete?: string;
}>();

const model = defineModel<string>({ default: '' });
const shown = ref(false);
</script>

<template>
  <Input
    :id="id"
    v-model="model"
    :type="shown ? 'text' : 'password'"
    :invalid="invalid"
    :described-by="describedBy"
    :placeholder="placeholder"
    :autocomplete="autocomplete"
  >
    <template #suffix>
      <button
        type="button"
        class="text-text/55 hover:text-text grid place-items-center"
        :aria-label="shown ? 'Hide password' : 'Show password'"
        :aria-pressed="shown"
        data-test="password-toggle"
        @click="shown = !shown"
      >
        <PhEyeSlash v-if="shown" :size="16" aria-hidden="true" />
        <PhEye v-else :size="16" aria-hidden="true" />
      </button>
    </template>
  </Input>
</template>
