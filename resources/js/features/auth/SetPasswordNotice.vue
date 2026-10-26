<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import type { SharedProps } from '../../types/shared';

// Shown to a person who signed up with Google or GitHub and so has no password. Changing the password or email,
// two-factor settings and deleting the account all ask for one, and the emailed link is how they set it.
const page = usePage<SharedProps>();
const needed = computed(() => page.props.auth.user?.hasPassword === false);

function send(): void {
  router.post('/account/password/link', {}, { preserveScroll: true });
}
</script>

<template>
  <InlineNotice
    v-if="needed"
    title="You sign in with Google or GitHub, so you have no password yet"
    data-test="set-password-notice"
  >
    Changing your email, turning on two-factor and deleting your account ask for a password. We will
    email you a link to set one.
    <div class="mt-2">
      <Button size="sm" data-test="set-password-send" @click="send">Email me the link</Button>
    </div>
  </InlineNotice>
</template>
