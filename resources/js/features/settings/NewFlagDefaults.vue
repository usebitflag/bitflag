<script setup lang="ts">
import Checkbox from '../../components/ui/Checkbox.vue';
import Field from '../../components/ui/Field.vue';
import Select from '../../components/ui/Select.vue';

defineProps<{
  disabled: boolean;
  errors: Record<string, string | undefined>;
}>();

const flagKeyFormat = defineModel<string>('flagKeyFormat', { required: true });
const staleAfterDays = defineModel<string>('staleAfterDays', { required: true });
const requireDescription = defineModel<boolean>('requireDescription', { required: true });
const requireOwner = defineModel<boolean>('requireOwner', { required: true });
const clientVisible = defineModel<boolean>('clientVisible', { required: true });

const formats = [
  { value: 'kebab', label: 'kebab-case · new-checkout' },
  { value: 'snake', label: 'snake_case · new_checkout' },
  { value: 'any', label: 'Any format' },
];
const staleOptions = [14, 30, 60, 90].map((days) => ({
  value: String(days),
  label: `${days} days without checks`,
}));
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="grid grid-cols-2 items-start gap-3.5">
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="Key format"
        :error="errors.flag_key_format"
      >
        <Select
          :id="id"
          v-model="flagKeyFormat"
          :options="formats"
          :disabled="disabled"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="flag-key-format"
        />
      </Field>
      <Field
        v-slot="{ id, describedBy, invalid }"
        label="Mark as stale after"
        help="Stale flags show up in Analytics as safe to clean up."
        :error="errors.stale_after_days"
      >
        <Select
          :id="id"
          v-model="staleAfterDays"
          :options="staleOptions"
          :disabled="disabled"
          :invalid="invalid"
          :described-by="describedBy"
          data-test="stale-after"
        />
      </Field>
    </div>
    <Checkbox
      v-model="requireDescription"
      label="Require a description"
      help="New flags can't be created without one."
      :disabled="disabled"
      data-test="require-description"
    />
    <Checkbox
      v-model="requireOwner"
      label="Require an owner"
      help="Every flag names the person responsible for cleaning it up."
      :disabled="disabled"
      data-test="require-owner"
    />
    <Checkbox
      v-model="clientVisible"
      label="Client-visible by default"
      help="Browser keys can only read client-visible flags. Leaving this off is safer."
      :disabled="disabled"
      data-test="client-visible"
    />
  </div>
</template>
