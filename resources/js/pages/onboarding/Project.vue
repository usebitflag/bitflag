<script setup lang="ts">
import { PhX } from '@phosphor-icons/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Field from '../../components/ui/Field.vue';
import IconButton from '../../components/ui/IconButton.vue';
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

function addEnvironment(): void {
  if (form.environments.length < 10) form.environments.push({ name: '', key: '' });
}

function removeEnvironment(index: number): void {
  if (form.environments.length > 1) form.environments.splice(index, 1);
}

function nameEnvironment(index: number, name: string): void {
  const environment = form.environments[index];
  if (!environment) return;
  const derived = environment.name
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^[^a-z]+/, '')
    .replace(/^-+|-+$/g, '');
  // The key follows the name until it has been edited by hand.
  if (environment.key === '' || environment.key === derived) {
    environment.key = name
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^[^a-z]+/, '')
      .replace(/^-+|-+$/g, '');
  }
  environment.name = name;
}

function submit(): void {
  form.post(`/onboarding/${props.organization.slug}/project`);
}
</script>

<template>
  <Head title="Create your first project" />
  <OnboardingLayout
    step="project"
    title="Create your first project"
    :subtitle="`In ${organization.name}.`"
  >
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
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
        label="Project key"
        help="Used by SDKs and the CLI."
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

      <fieldset class="flex flex-col gap-2">
        <legend class="text-sm font-medium">Environments</legend>
        <ul class="flex flex-col gap-2">
          <li
            v-for="(environment, index) in form.environments"
            :key="index"
            class="flex items-center gap-2"
            data-test="environment-row"
          >
            <Input
              :model-value="environment.name"
              placeholder="Name"
              :described-by="undefined"
              :data-test="`environment-name-${index}`"
              @update:model-value="nameEnvironment(index, $event)"
            />
            <Input
              v-model="environment.key"
              mono
              placeholder="key"
              :data-test="`environment-key-${index}`"
            />
            <IconButton
              label="Remove environment"
              :disabled="form.environments.length === 1"
              :data-test="`environment-remove-${index}`"
              @click="removeEnvironment(index)"
            >
              <PhX :size="16" aria-hidden="true" />
            </IconButton>
          </li>
        </ul>
        <p v-if="form.errors.environments" class="text-danger-text text-xs" role="alert">
          {{ form.errors.environments }}
        </p>
        <div>
          <Button size="sm" data-test="environment-add" @click="addEnvironment"
            >Add environment</Button
          >
        </div>
        <p class="text-muted text-xs">Production starts protected: publishing needs a reason.</p>
      </fieldset>

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
