<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Dialog from '../../components/ui/Dialog.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import type { SharedProps } from '../../types/shared';
import OtpInput from './OtpInput.vue';
import { ApiError, sendJson } from './sendJson';
import { dismissSessionExpiry, sessionExpiry } from './useSessionExpiry';

const page = usePage<SharedProps>();
const email = computed(() => page.props.auth.user?.email ?? '');

const step = ref<'password' | 'code'>('password');
const password = ref('');
const code = ref('');
const busy = ref(false);
const error = ref('');

const open = computed({
  get: () => sessionExpiry.value !== null,
  set: (value) => {
    // The dialog cannot be dismissed: the page behind it has no session. Only signing in or leaving closes it.
    if (!value && sessionExpiry.value !== null) return;
  },
});

watch(
  () => sessionExpiry.value,
  (value) => {
    if (value !== null) {
      step.value = 'password';
      password.value = '';
      code.value = '';
      error.value = '';
    }
  },
);

function finish(): void {
  dismissSessionExpiry();
  router.reload();
}

async function signIn(): Promise<void> {
  busy.value = true;
  error.value = '';
  try {
    const result = await sendJson<{ two_factor?: boolean }>('POST', '/signin', {
      email: email.value,
      password: password.value,
    });
    password.value = '';
    if (result.two_factor === true) {
      step.value = 'code';
    } else {
      finish();
    }
  } catch (caught) {
    error.value =
      caught instanceof ApiError
        ? (caught.first('email') ?? caught.message)
        : 'Something went wrong.';
  } finally {
    busy.value = false;
  }
}

async function confirmCode(): Promise<void> {
  busy.value = true;
  error.value = '';
  try {
    await sendJson('POST', '/two-factor-challenge', { code: code.value });
    finish();
  } catch (caught) {
    code.value = '';
    error.value =
      caught instanceof ApiError
        ? (caught.first('code') ?? caught.first('email') ?? caught.message)
        : 'Something went wrong.';
    // The attempt ended: begin again from the password.
    if (caught instanceof ApiError && caught.first('email')) step.value = 'password';
  } finally {
    busy.value = false;
  }
}

function useAnotherAccount(): void {
  dismissSessionExpiry();
  router.visit('/signin');
}
</script>

<template>
  <Dialog
    v-model:open="open"
    title="Your session ended"
    :description="sessionExpiry?.message"
    data-test="session-expired-dialog"
  >
    <form
      v-if="step === 'password'"
      class="flex flex-col gap-3"
      novalidate
      @submit.prevent="signIn"
    >
      <p class="text-muted text-sm" data-test="session-expired-account">
        Sign in again as {{ email }}.
      </p>
      <Field v-slot="{ id, describedBy, invalid }" label="Password" :error="error">
        <Input
          :id="id"
          v-model="password"
          type="password"
          autocomplete="current-password"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="session-expired-password"
        />
      </Field>
      <div class="flex justify-between gap-2">
        <Button variant="ghost" data-test="session-expired-other" @click="useAnotherAccount">
          Use another account
        </Button>
        <Button type="submit" variant="primary" :loading="busy" data-test="session-expired-submit">
          Sign in
        </Button>
      </div>
    </form>

    <form v-else class="flex flex-col gap-3" novalidate @submit.prevent="confirmCode">
      <p class="text-sm">Enter the 6-digit code from your authenticator app.</p>
      <OtpInput v-model="code" :invalid="Boolean(error)" @complete="confirmCode" />
      <p
        v-if="error"
        class="text-danger-text text-xs"
        role="alert"
        data-test="session-expired-error"
      >
        {{ error }}
      </p>
      <Button
        type="submit"
        variant="primary"
        :loading="busy"
        data-test="session-expired-code-submit"
      >
        Continue
      </Button>
    </form>
  </Dialog>
</template>
