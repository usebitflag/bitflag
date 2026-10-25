<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import PasswordChecklist from '../../features/auth/PasswordChecklist.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';

const props = defineProps<{ token: string; user: string; usable: boolean }>();

const form = useForm({ token: props.token, user: props.user, password: '' });
const confirmation = ref('');
const common = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
  () => form.password,
  (password) => {
    clearTimeout(timer);
    if (password.length === 0) {
      common.value = false;
      return;
    }
    timer = setTimeout(async () => {
      try {
        const response = await fetch('/password/check', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(
              document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
            ),
          },
          body: JSON.stringify({ password }),
        });
        if (response.ok) common.value = Boolean((await response.json()).common);
      } catch {
        common.value = false;
      }
    }, 300);
  },
);

const canSave = computed(
  () => [...form.password].length >= 12 && !common.value && confirmation.value === form.password,
);

function submit(): void {
  form.post('/reset-password', {
    onFinish: () => {
      form.reset('password');
      confirmation.value = '';
    },
  });
}
</script>

<template>
  <Head title="Set a new password" />
  <AuthLayout title="Set a new password" subtitle="You'll be signed out of other sessions.">
    <InlineNotice v-if="!usable" tone="warning" title="This link has expired or was already used.">
      <Link href="/forgot-password" class="underline" data-test="reset-request-new">
        Request a new one
      </Link>
    </InlineNotice>
    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <InlineNotice
        v-if="form.errors.token"
        tone="danger"
        :title="form.errors.token"
        data-test="reset-token-error"
      />
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="New password"
        :error="form.errors.password"
      >
        <Input
          :id="id"
          v-model="form.password"
          type="password"
          autocomplete="new-password"
          placeholder="At least 12 characters"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="reset-password"
        />
      </Field>
      <PasswordChecklist reset class="-mt-1" :password="form.password" email="" :common="common" />
      <Field v-slot="{ id }" label="Confirm password">
        <Input
          :id="id"
          v-model="confirmation"
          type="password"
          autocomplete="new-password"
          data-test="reset-confirm"
        />
      </Field>
      <Button
        type="submit"
        variant="primary"
        size="lg"
        block
        :loading="form.processing"
        :disabled="!canSave"
        data-test="reset-submit"
      >
        Save password
      </Button>
    </form>
  </AuthLayout>
</template>
