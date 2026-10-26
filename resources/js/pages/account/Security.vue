<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import PasswordChecklist from '../../features/auth/PasswordChecklist.vue';
import SetPasswordNotice from '../../features/auth/SetPasswordNotice.vue';
import SessionsPanel, {
  type SessionRow,
  type TrustedBrowserRow,
} from '../../features/auth/SessionsPanel.vue';
import TwoFactorPanel from '../../features/auth/TwoFactorPanel.vue';
import { sendJson } from '../../features/auth/sendJson';
import AccountLayout from '../../layouts/AccountLayout.vue';
import type { SharedProps } from '../../types/shared';

defineProps<{
  twoFactor: { enabled: boolean; recoveryCodesLeft: number };
  requiredBy: string | null;
  sessions: SessionRow[];
  trustedBrowsers: TrustedBrowserRow[];
}>();

const page = usePage<SharedProps>();
const hasPassword = computed(() => page.props.auth.user?.hasPassword !== false);

const form = useForm({ current_password: '', password: '' });
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
        common.value = Boolean(
          (await sendJson<{ common: boolean }>('POST', '/password/check', { password })).common,
        );
      } catch {
        common.value = false;
      }
    }, 300);
  },
);

function changePassword(): void {
  form.put('/account/password', { onSuccess: () => form.reset() });
}
</script>

<template>
  <Head title="Account security" />
  <AccountLayout title="Security">
    <div class="flex max-w-xl flex-col gap-8">
      <SetPasswordNotice />
      <section v-if="hasPassword" class="flex flex-col gap-4" aria-labelledby="password-heading">
        <div>
          <h2 id="password-heading" class="font-heading text-lg">Password</h2>
          <p class="text-muted text-sm">Changing it signs you out of your other sessions.</p>
        </div>
        <form class="flex flex-col gap-3" novalidate @submit.prevent="changePassword">
          <Field
            v-slot="{ id, describedBy, invalid }"
            label="Current password"
            :error="form.errors.current_password"
          >
            <Input
              :id="id"
              v-model="form.current_password"
              type="password"
              autocomplete="current-password"
              :invalid="invalid"
              :described-by="describedBy"
              data-test="current-password"
            />
          </Field>
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
              :invalid="invalid"
              :described-by="describedBy"
              data-test="new-password"
            />
          </Field>
          <PasswordChecklist :password="form.password" email="" :common="common" />
          <div>
            <Button
              type="submit"
              variant="primary"
              :loading="form.processing"
              data-test="change-password"
            >
              Change password
            </Button>
          </div>
        </form>
      </section>

      <TwoFactorPanel
        :has-password="hasPassword"
        :enabled="twoFactor.enabled"
        :recovery-codes-left="twoFactor.recoveryCodesLeft"
        :required-by="requiredBy"
      />

      <SessionsPanel :sessions="sessions" :trusted-browsers="trustedBrowsers" />
    </div>
  </AccountLayout>
</template>
