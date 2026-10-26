<script setup lang="ts">
import { PhGithubLogo, PhGoogleLogo } from '@phosphor-icons/vue';
import Button from '../../components/ui/Button.vue';

export interface SocialProviderOption {
  key: string;
  label: string;
}

defineProps<{ providers: SocialProviderOption[] }>();
</script>

<template>
  <div v-if="providers.length" class="flex flex-col gap-4" data-test="social-buttons">
    <div :class="['grid gap-2', providers.length > 1 ? 'grid-cols-2' : 'grid-cols-1']">
      <Button
        v-for="provider in providers"
        :key="provider.key"
        :href="`/auth/${provider.key}/redirect`"
        external
        class="justify-center"
        :data-test="`social-${provider.key}`"
      >
        <template #icon>
          <PhGithubLogo v-if="provider.key === 'github'" :size="16" aria-hidden="true" />
          <PhGoogleLogo v-else :size="16" aria-hidden="true" />
        </template>
        {{ provider.label }}
      </Button>
    </div>
    <div class="text-text/50 flex items-center gap-2.5 text-xs" aria-hidden="true">
      <span class="bg-divider h-px flex-1" />or with email<span class="bg-divider h-px flex-1" />
    </div>
  </div>
</template>
