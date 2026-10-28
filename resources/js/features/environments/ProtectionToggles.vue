<script setup lang="ts">
import Switch from '../../components/ui/Switch.vue';

defineProps<{
  disabled: boolean;
  /** False when the plan does not include required approvals. */
  approvalsAvailable: boolean;
}>();

const isProtected = defineModel<boolean>('protected', { required: true });
const requireApproval = defineModel<boolean>('requireApproval', { required: true });
</script>

<template>
  <div class="flex flex-col gap-4" data-test="protection-toggles">
    <Switch
      v-model="isProtected"
      label="Protected"
      help="Only Owners and Publishers can publish, and every publish needs a reason."
      :disabled="disabled"
      data-test="protection-protected"
    />
    <div class="flex flex-col gap-1.5">
      <Switch
        v-model="requireApproval"
        label="Require a second reviewer"
        help="Someone other than the editor approves the change before it is published."
        :disabled="disabled || !isProtected || !approvalsAvailable"
        data-test="protection-approval"
      />
      <p
        v-if="!approvalsAvailable"
        class="text-text/62 pl-[46px] text-xs"
        data-test="approvals-pro"
      >
        Required approvals are on Pro. Upgrade to turn this on.
      </p>
      <p v-else-if="!isProtected" class="text-text/62 pl-[46px] text-xs">
        Turn on protection to require a reviewer.
      </p>
    </div>
  </div>
</template>
