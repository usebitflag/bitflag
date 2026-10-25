<script setup lang="ts">
import { PhCheckCircle, PhCircle } from '@phosphor-icons/vue';
import { computed } from 'vue';

const props = defineProps<{ password: string; email: string; common: boolean }>();

// The first and third rule are checked here; the common-password rule is answered by the server.
const rules = computed(() => {
  const candidate = props.password.trim().toLowerCase();
  const email = props.email.trim().toLowerCase();
  const local = email.split('@')[0] ?? '';
  const typed = props.password.length > 0;

  return [
    { key: 'length', label: 'At least 12 characters', met: [...props.password].length >= 12 },
    { key: 'common', label: 'Not a common password', met: typed && !props.common },
    {
      key: 'email',
      label: 'Not your email',
      met: typed && candidate !== email && (local === '' || candidate !== local),
    },
  ];
});
</script>

<template>
  <ul
    class="flex flex-col gap-1 text-xs"
    aria-label="Password requirements"
    data-test="password-checklist"
  >
    <li
      v-for="rule in rules"
      :key="rule.key"
      :class="['flex items-center gap-1.5', rule.met ? 'text-success-text' : 'text-muted']"
      :data-test="`password-rule-${rule.key}`"
      :data-met="rule.met"
    >
      <PhCheckCircle v-if="rule.met" :size="14" aria-hidden="true" />
      <PhCircle v-else :size="14" aria-hidden="true" />
      <span>{{ rule.label }}</span>
      <span class="sr-only">{{ rule.met ? 'met' : 'not met yet' }}</span>
    </li>
  </ul>
</template>
