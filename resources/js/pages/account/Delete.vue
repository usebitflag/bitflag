<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Input from '../../components/ui/Input.vue';
import SetPasswordNotice from '../../features/auth/SetPasswordNotice.vue';
import AccountLayout from '../../layouts/AccountLayout.vue';

const props = defineProps<{ email: string; soleOwnerOf: string[] }>();

const form = useForm({ email: '', password: '' });
const page = usePage<{ auth: { user: { hasPassword?: boolean } | null } }>();
const hasPassword = computed(() => page.props.auth.user?.hasPassword !== false);
const blocked = computed(() => props.soleOwnerOf.length > 0);
const accountError = computed(() => (form.errors as Record<string, string | undefined>).account);

function submit(): void {
  form.delete('/account', { onFinish: () => form.reset('password') });
}
</script>

<template>
  <Head title="Delete account" />
  <AccountLayout title="Delete account">
    <SetPasswordNotice class="mb-4 max-w-md" />
    <section class="flex max-w-md flex-col gap-4" aria-labelledby="delete-heading">
      <div>
        <h2 id="delete-heading" class="font-heading text-lg">Delete your account</h2>
        <p class="text-muted text-sm">
          You are removed from every organization and project, signed out everywhere, and your email
          address is released. This cannot be undone. What you changed stays in the history under
          your name.
        </p>
      </div>

      <InlineNotice
        v-if="blocked"
        tone="warning"
        title="You can't delete your account yet"
        data-test="delete-blocked"
      >
        You are the only owner of {{ soleOwnerOf.join(', ') }}. Make someone else an owner, or
        delete the organization, first.
      </InlineNotice>
      <InlineNotice
        v-if="accountError"
        tone="danger"
        :title="accountError"
        data-test="delete-error"
      />

      <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <Field
          v-slot="{ id, describedBy, invalid }"
          :label="`Type ${email} to confirm`"
          :error="form.errors.email"
        >
          <Input
            :id="id"
            v-model="form.email"
            :invalid="invalid"
            :described-by="describedBy"
            :disabled="blocked"
            data-test="delete-email"
          />
        </Field>
        <Field
          v-slot="{ id, describedBy, invalid }"
          label="Your password"
          :error="form.errors.password"
        >
          <Input
            :id="id"
            v-model="form.password"
            type="password"
            autocomplete="current-password"
            :invalid="invalid"
            :described-by="describedBy"
            :disabled="blocked || !hasPassword"
            data-test="delete-password"
          />
        </Field>
        <div>
          <Button
            type="submit"
            variant="destructive"
            :loading="form.processing"
            :disabled="blocked || !hasPassword || form.email === '' || form.password === ''"
            data-test="delete-submit"
          >
            Delete my account
          </Button>
        </div>
      </form>
    </section>
  </AccountLayout>
</template>
