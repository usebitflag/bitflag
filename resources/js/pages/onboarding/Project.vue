<script setup lang="ts">
import { PhCode, PhFlask, PhShieldCheck } from '@phosphor-icons/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import Input from '../../components/ui/Input.vue';
import OnboardingLayout from '../../layouts/OnboardingLayout.vue';

const props = defineProps<{
  organization: { name: string; slug: string };
  environments: Array<{ name: string; key: string }>;
}>();

const form = useForm({
  name: '',
  key: '',
  environments: props.environments.map((environment) => ({ ...environment })),
});
const keyEdited = ref(false);

watch(
  () => form.name,
  (name) => {
    if (keyEdited.value) return;
    form.key = name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^[^a-z]+/, '')
      .replace(/^-+|-+$/g, '')
      .slice(0, 40);
  },
);

// Environments start as the template names them; they can be renamed or added once the project exists.
const icons = [PhCode, PhFlask, PhShieldCheck];

function submit(): void {
  form.post(`/onboarding/${props.organization.slug}/project`);
}
</script>

<template>
  <Head title="Create your first project" />
  <OnboardingLayout
    step="project"
    title="Create your first project"
    subtitle="A project is one app or service. Each has its own flags, environments and keys."
    :width="460"
  >
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <div class="grid grid-cols-2 items-start gap-3">
        <Field v-slot="{ id, describedBy, invalid }" label="Project name" :error="form.errors.name">
          <Input
            :id="id"
            v-model="form.name"
            :invalid="invalid"
            :described-by="describedBy"
            data-test="project-name"
          />
        </Field>
        <Field
          v-slot="{ id, describedBy, invalid }"
          label="Key"
          help="Used by SDKs and the CLI"
          :error="form.errors.key"
        >
          <Input
            :id="id"
            v-model="form.key"
            mono
            :invalid="invalid"
            :described-by="describedBy"
            data-test="project-key"
            @input="keyEdited = true"
          />
        </Field>
      </div>

      <div class="flex flex-col gap-[5px]">
        <span class="text-text/70 text-sm font-medium">Environments</span>
        <ul class="flex flex-wrap gap-1.5" data-test="environment-list">
          <li
            v-for="(environment, index) in form.environments"
            :key="index"
            class="ring-divider inline-flex items-center gap-1.5 rounded-md px-2.5 py-[5px] font-mono text-[12.5px] leading-[15px] ring-1 ring-inset"
            data-test="environment-row"
          >
            <component :is="icons[index] ?? PhCode" :size="13" aria-hidden="true" />
            {{ environment.key }}
          </li>
        </ul>
        <p v-if="form.errors.environments" class="text-danger-text text-xs" role="alert">
          {{ form.errors.environments }}
        </p>
        <p class="text-text/58 mt-0.5 text-xs">
          Production starts protected: publishing needs a reason. You can rename or add environments
          later.
        </p>
      </div>

      <Button
        type="submit"
        variant="primary"
        block
        :loading="form.processing"
        data-test="project-create"
      >
        Create project
      </Button>
    </form>
  </OnboardingLayout>
</template>
