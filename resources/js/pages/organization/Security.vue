<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Switch from '../../components/ui/Switch.vue';
import Textarea from '../../components/ui/Textarea.vue';
import OrgSettingsLayout from '../../layouts/OrgSettingsLayout.vue';

const props = defineProps<{
  settings: {
    slug: string;
    version: number;
    requireMfa: boolean;
    idleTimeoutMinutes: number | null;
    allowedEmailDomains: string[];
  };
  membersWithoutTwoFactor: number;
}>();

const form = useForm({
  require_mfa: props.settings.requireMfa,
  idle_timeout_minutes: props.settings.idleTimeoutMinutes as number | null,
  domains: props.settings.allowedEmailDomains.join('\n'),
  version: props.settings.version,
});

const idleOptions = [
  { value: null, label: 'Platform default (7 days)' },
  { value: 60, label: '1 hour' },
  { value: 720, label: '12 hours' },
  { value: 1440, label: '24 hours' },
];

// The server names this field `allowed_email_domains`, while the form edits it as text under `domains`.
const domainError = computed(
  () => (form.errors as Record<string, string | undefined>).allowed_email_domains,
);
const withoutText = computed(() =>
  props.membersWithoutTwoFactor === 0
    ? 'Everyone already uses two-factor.'
    : `${props.membersWithoutTwoFactor} ${props.membersWithoutTwoFactor === 1 ? 'member has' : 'members have'} not set it up. They will be asked to the next time they open ${props.settings.slug}.`,
);

function save(): void {
  form
    .transform((data) => ({
      require_mfa: data.require_mfa,
      idle_timeout_minutes: data.idle_timeout_minutes,
      allowed_email_domains: data.domains.split(/[\s,;]+/).filter((domain) => domain !== ''),
      version: data.version,
    }))
    .put(`/${props.settings.slug}/settings/security`);
}
</script>

<template>
  <Head title="Organization security" />
  <OrgSettingsLayout title="Organization security">
    <form class="flex max-w-xl flex-col gap-6" novalidate @submit.prevent="save">
      <InlineNotice
        v-if="form.errors.version"
        tone="warning"
        :title="form.errors.version"
        data-test="security-conflict"
      />

      <section class="flex flex-col gap-2" aria-labelledby="mfa-heading">
        <div class="flex items-start justify-between gap-4">
          <div>
            <h2 id="mfa-heading" class="font-heading text-lg">Require two-factor sign-in</h2>
            <p class="text-muted text-sm" data-test="security-mfa-count">{{ withoutText }}</p>
          </div>
          <Switch
            v-model="form.require_mfa"
            label="Require two-factor sign-in"
            data-test="security-mfa"
          />
        </div>
      </section>

      <section class="flex flex-col gap-2" aria-labelledby="idle-heading">
        <h2 id="idle-heading" class="font-heading text-lg">Sign out after inactivity</h2>
        <p class="text-muted text-sm">
          Applies to people while they work in this organization. It can only be shorter than the
          platform's 7 days.
        </p>
        <select
          v-model="form.idle_timeout_minutes"
          class="border-control-border bg-surface h-9 w-64 rounded-md border px-2 text-sm"
          aria-labelledby="idle-heading"
          data-test="security-idle"
        >
          <option v-for="option in idleOptions" :key="String(option.value)" :value="option.value">
            {{ option.label }}
          </option>
        </select>
        <p v-if="form.errors.idle_timeout_minutes" class="text-danger-text text-xs" role="alert">
          {{ form.errors.idle_timeout_minutes }}
        </p>
      </section>

      <section class="flex flex-col gap-2" aria-labelledby="domains-heading">
        <h2 id="domains-heading" class="font-heading text-lg">Allowed email domains</h2>
        <Field
          v-slot="{ id, describedBy, invalid }"
          label="Domains"
          help="One per line. Leave empty to allow any domain. People at other domains cannot be invited."
          :error="domainError"
        >
          <Textarea
            :id="id"
            v-model="form.domains"
            :invalid="invalid"
            :described-by="describedBy"
            data-test="security-domains"
          />
        </Field>
      </section>

      <div class="flex items-center gap-4">
        <Button type="submit" variant="primary" :loading="form.processing" data-test="security-save"
          >Save</Button
        >
        <Link
          href="/account/security"
          class="text-muted text-sm underline"
          data-test="security-your-sessions"
          >Your sessions</Link
        >
      </div>
    </form>
  </OrgSettingsLayout>
</template>
