<script setup lang="ts">
import Button from '../../components/ui/Button.vue';

export interface SocialProviderOption {
  key: string;
  label: string;
}

defineProps<{ providers: SocialProviderOption[] }>();
</script>

<template>
  <div v-if="providers.length" class="flex flex-col gap-4" data-test="social-buttons">
    <div class="flex flex-col gap-2.5">
      <Button
        v-for="provider in providers"
        :key="provider.key"
        :href="`/auth/${provider.key}/redirect`"
        external
        size="lg"
        block
        class="relative"
        :data-test="`social-${provider.key}`"
      >
        <!-- The logos keep their own colors: Google's is a file under public/images/brand, GitHub's follows the text color. -->
        <svg
          v-if="provider.key === 'github'"
          class="absolute left-3"
          width="18"
          height="18"
          viewBox="0 0 16 16"
          fill="currentColor"
          aria-hidden="true"
        >
          <path
            d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"
          />
        </svg>
        <img
          v-else
          class="absolute left-3"
          src="/images/brand/google.svg"
          width="18"
          height="18"
          alt=""
          aria-hidden="true"
        />
        Continue with {{ provider.label }}
      </Button>
    </div>
    <div class="text-text/50 flex items-center gap-2.5 text-xs" aria-hidden="true">
      <span class="bg-divider h-px flex-1" />or with email<span class="bg-divider h-px flex-1" />
    </div>
  </div>
</template>
