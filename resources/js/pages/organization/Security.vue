<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '../../components/ui/Button.vue';
import Card from '../../components/ui/Card.vue';
import Field from '../../components/ui/Field.vue';
import InlineNotice from '../../components/ui/InlineNotice.vue';
import Switch from '../../components/ui/Switch.vue';
import Input from '../../components/ui/Input.vue';
import Select from '../../components/ui/Select.vue';
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
  idle_timeout_minutes:
    props.settings.idleTimeoutMinutes === null ? '' : String(props.settings.idleTimeoutMinutes),
  domains: props.settings.allowedEmailDomains.join(', '),
  version: props.settings.version,
});

const idleOptions = [
  { value: '', label: 'Platform default (7 days)' },
  { value: '60', label: '1 hour' },
  { value: '720', label: '12 hours' },
  { value: '1440', label: '24 hours' },
];

// The server names this field `allowed_email_domains`, while the form edits it as text under `domains`.
const domainError = computed(
  () => (form.errors as Record<string, string | undefined>).allowed_email_domains,
);
const withoutText = computed(() =>
  props.membersWithoutTwoFactor === 0
    ? 'Everyone already uses two-factor.'
    : `Members without it are asked to set it up at their next sign-in. ${props.membersWithoutTwoFactor} ${props.membersWithoutTwoFactor === 1 ? "person doesn't" : "people don't"} have it yet.`,
);

function save(): void {
  form
    .transform((data) => ({
      require_mfa: data.require_mfa,
      idle_timeout_minutes:
        data.idle_timeout_minutes === '' ? null : Number(data.idle_timeout_minutes),
      allowed_email_domains: data.domains.split(/[\s,;]+/).filter((domain) => domain !== ''),
      version: data.version,
    }))
    .put(`/${props.settings.slug}/settings/security`);
}
</script>

<template>
  <Head title="Organization security" />
  <OrgSettingsLayout title="Security" :description="`Rules for everyone in ${settings.slug}.`">
    <form class="flex max-w-[860px] flex-col gap-4" novalidate @submit.prevent="save">
      <InlineNotice
        v-if="form.errors.version"
        tone="warning"
        :title="form.errors.version"
        data-test="security-conflict"
      />

      <Card title="Sign-in">
        <div class="flex flex-col gap-4">
          <div>
            <Switch
              v-model="form.require_mfa"
              label="Require two-factor for everyone"
              data-test="security-mfa"
            />
            <p class="text-text/62 mt-0.5 ml-[46px] text-[12.5px]" data-test="security-mfa-count">
              {{ withoutText }}
            </p>
          </div>

          <Field
            v-slot="{ id, describedBy }"
            label="Sign out after inactivity"
            help="Applies to people while they work in this organization. It can only be shorter than the platform's 7 days."
            :error="form.errors.idle_timeout_minutes"
          >
            <Select
              :id="id"
              v-model="form.idle_timeout_minutes"
              class="w-64"
              :options="idleOptions"
              :described-by="describedBy"
              data-test="security-idle"
            />
          </Field>

          <Field
            v-slot="{ id, describedBy, invalid }"
            label="Allowed email domains"
            help="Invitations to other domains are blocked. Leave empty to allow any domain."
            :error="domainError"
          >
            <Input
              :id="id"
              v-model="form.domains"
              :invalid="invalid"
              :described-by="describedBy"
              placeholder="acme.dev, acme.com"
              data-test="security-domains"
            />
          </Field>
        </div>
      </Card>

      <div class="flex items-center gap-4">
        <Button type="submit" variant="primary" :loading="form.processing" data-test="security-save"
          >Save</Button
        >
        <Link
          href="/account/security"
          class="text-primary-ink text-sm hover:underline"
          data-test="security-your-sessions"
          >Your sessions</Link
        >
      </div>
    </form>
  </OrgSettingsLayout>
</template>
