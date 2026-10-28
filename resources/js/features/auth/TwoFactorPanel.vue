<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Input from '../../components/ui/Input.vue';
import OtpInput from './OtpInput.vue';
import RecoveryCodesPanel from './RecoveryCodesPanel.vue';
import { ApiError, sendJson } from './sendJson';

const props = withDefaults(
  defineProps<{
    enabled: boolean;
    recoveryCodesLeft: number;
    requiredBy: string | null;
    /** False when the person has no password yet: starting needs one. */
    hasPassword?: boolean;
  }>(),
  { hasPassword: true },
);

type Step = 'idle' | 'password' | 'scan' | 'codes' | 'regenerate' | 'disable';

const step = ref<Step>('idle');
const password = ref('');
const code = ref('');
const enrollment = ref<{ secret: string; qr: string } | null>(null);
const codes = ref<string[]>([]);
const acknowledged = ref(false);
const busy = ref(false);
const errors = ref<Record<string, string>>({});

const needsRegeneration = computed(() => props.enabled && props.recoveryCodesLeft <= 2);

function reset(next: Step = 'idle'): void {
  step.value = next;
  password.value = '';
  code.value = '';
  errors.value = {};
  acknowledged.value = false;
}

async function run(action: () => Promise<void>): Promise<void> {
  busy.value = true;
  errors.value = {};
  try {
    await action();
  } catch (error) {
    if (error instanceof ApiError) {
      errors.value = Object.fromEntries(
        Object.entries(error.fields).map(([field, messages]) => [field, messages[0] ?? '']),
      );
      if (Object.keys(errors.value).length === 0) errors.value = { general: error.message };
    } else {
      errors.value = { general: 'Something went wrong. Try again.' };
    }
  } finally {
    busy.value = false;
  }
}

const start = () =>
  run(async () => {
    enrollment.value = await sendJson('POST', '/account/two-factor', { password: password.value });
    password.value = '';
    step.value = 'scan';
  });

const confirm = () =>
  run(async () => {
    const result = await sendJson<{ recoveryCodes: string[] }>(
      'POST',
      '/account/two-factor/confirm',
      {
        code: code.value,
      },
    );
    codes.value = result.recoveryCodes;
    enrollment.value = null;
    code.value = '';
    step.value = 'codes';
  });

const regenerate = () =>
  run(async () => {
    const result = await sendJson<{ recoveryCodes: string[] }>('POST', '/account/recovery-codes', {
      password: password.value,
    });
    codes.value = result.recoveryCodes;
    password.value = '';
    step.value = 'codes';
  });

const disable = () =>
  run(async () => {
    await sendJson('DELETE', '/account/two-factor', { password: password.value, code: code.value });
    reset();
    router.reload({ only: ['twoFactor'] });
  });

function finish(): void {
  codes.value = [];
  reset();
  router.reload({ only: ['twoFactor'] });
}
</script>

<template>
  <section
    class="flex flex-col gap-4"
    aria-labelledby="two-factor-heading"
    data-test="two-factor-panel"
  >
    <div>
      <h2 id="two-factor-heading" class="font-heading text-lg">Two-factor sign-in</h2>
      <p class="text-muted text-sm">
        Ask for a code from an authenticator app after your password.
      </p>
    </div>

    <InlineNotice
      v-if="requiredBy && !enabled"
      tone="warning"
      :title="`${requiredBy} requires two-factor sign-in`"
      data-test="two-factor-required"
    >
      Turn it on to keep working in {{ requiredBy }}.
    </InlineNotice>
    <p v-if="errors.general" class="text-danger-text text-sm" role="alert">{{ errors.general }}</p>

    <!-- Off -->
    <div v-if="!enabled && step === 'idle'">
      <Button
        variant="primary"
        :disabled="!hasPassword"
        data-test="two-factor-enable"
        @click="reset('password')"
      >
        Turn on two-factor
      </Button>
    </div>

    <!-- On -->
    <div v-if="enabled && step === 'idle'" class="flex flex-col gap-3">
      <p class="text-sm" data-test="two-factor-state">
        Two-factor is on. {{ recoveryCodesLeft }} recovery
        {{ recoveryCodesLeft === 1 ? 'code' : 'codes' }} left.
      </p>
      <InlineNotice
        v-if="needsRegeneration"
        tone="warning"
        title="You are running low on recovery codes"
        data-test="regenerate-prompt"
      >
        Make a new set so you are not locked out if you lose your device.
      </InlineNotice>
      <div class="flex gap-2">
        <Button data-test="regenerate-open" @click="reset('regenerate')">New recovery codes</Button>
        <Button variant="destructive" data-test="disable-open" @click="reset('disable')">
          Turn off
        </Button>
      </div>
    </div>

    <!-- Password before starting, regenerating or turning off -->
    <form
      v-if="step === 'password' || step === 'regenerate' || step === 'disable'"
      class="flex flex-col gap-3"
      novalidate
      @submit.prevent="
        step === 'password' ? start() : step === 'regenerate' ? regenerate() : disable()
      "
    >
      <Field v-slot="{ id, describedBy, invalid }" label="Your password" :error="errors.password">
        <Input
          :id="id"
          v-model="password"
          type="password"
          autocomplete="current-password"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="two-factor-password"
        />
      </Field>
      <Field
        v-if="step === 'disable'"
        v-slot="{ id, describedBy, invalid }"
        label="A current code or a recovery code"
        :error="errors.code"
      >
        <Input
          :id="id"
          v-model="code"
          mono
          autocomplete="one-time-code"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="two-factor-disable-code"
        />
      </Field>
      <p
        v-if="errors.two_factor"
        class="text-danger-text text-sm"
        role="alert"
        data-test="two-factor-error"
      >
        {{ errors.two_factor }}
      </p>
      <div class="flex gap-2">
        <Button
          type="submit"
          :variant="step === 'disable' ? 'destructive' : 'primary'"
          :loading="busy"
          data-test="two-factor-continue"
        >
          {{ step === 'disable' ? 'Turn off two-factor' : 'Continue' }}
        </Button>
        <Button variant="ghost" data-test="two-factor-cancel" @click="reset()">Cancel</Button>
      </div>
    </form>

    <!-- Scan and confirm -->
    <form
      v-if="step === 'scan' && enrollment"
      class="flex flex-col gap-3"
      novalidate
      @submit.prevent="confirm"
    >
      <p class="text-sm">
        Scan this code with your authenticator app, or enter the key by hand. Then enter the 6-digit
        code it shows.
      </p>
      <!-- eslint-disable-next-line vue/no-v-html -- drawn by our own server from the secret, never from user input -->
      <div class="bg-white w-fit rounded-md p-2" data-test="two-factor-qr" v-html="enrollment.qr" />
      <p class="text-sm">
        Key: <code class="font-mono" data-test="two-factor-secret">{{ enrollment.secret }}</code>
      </p>
      <OtpInput v-model="code" :invalid="Boolean(errors.code)" />
      <p
        v-if="errors.code"
        class="text-danger-text text-xs"
        role="alert"
        data-test="two-factor-code-error"
      >
        {{ errors.code }}
      </p>
      <div class="flex gap-2">
        <Button type="submit" variant="primary" :loading="busy" data-test="two-factor-confirm">
          Turn on
        </Button>
        <Button variant="ghost" @click="reset()">Cancel</Button>
      </div>
    </form>

    <!-- Recovery codes, shown once -->
    <div v-if="step === 'codes'" class="flex flex-col gap-3">
      <RecoveryCodesPanel :codes="codes" @acknowledged="acknowledged = $event" />
      <div>
        <Button
          variant="primary"
          :disabled="!acknowledged"
          data-test="recovery-done"
          @click="finish"
        >
          Done
        </Button>
      </div>
    </div>
  </section>
</template>
