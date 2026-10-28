<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, reactive, ref, toRef, watch } from 'vue';
import Button from '../../components/ui/Button.vue';
import Dialog from '../../components/ui/Dialog.vue';
import Field from '../../components/ui/Field.vue';
import Textarea from '../../components/ui/Textarea.vue';
import { useEmailList } from '../../composables/useEmailList';

export interface InvitableProject {
  id: string;
  name: string;
}

const props = defineProps<{
  action: string;
  projects: InvitableProject[];
  /** Whether the person inviting may choose the organization role (Org Owners). Otherwise the guest joins as a Member. */
  canChooseOrgRole: boolean;
  /** A project to preselect, for the project Members tab. */
  fixedProjectId?: string;
  errors?: Record<string, string | undefined>;
}>();

const open = defineModel<boolean>('open', { default: false });

const emails = ref('');
const orgRole = ref<'member' | 'owner'>('member');
const roles = ['viewer', 'developer', 'publisher', 'owner'] as const;
const grants = reactive<Record<string, { selected: boolean; role: (typeof roles)[number] }>>({});
const busy = ref(false);

const { count, invalid, overLimit } = useEmailList(toRef(emails, 'value'));

watch(
  () => [props.projects, open.value] as const,
  () => {
    for (const project of props.projects) {
      grants[project.id] ??= { selected: project.id === props.fixedProjectId, role: 'developer' };
    }
  },
  { immediate: true },
);

const summary = computed(() =>
  count.value === 0
    ? 'No one yet.'
    : `${count.value} ${count.value === 1 ? 'person' : 'people'} will be invited.`,
);

function send(): void {
  busy.value = true;
  router.post(
    props.action,
    {
      emails: emails.value,
      org_role: props.canChooseOrgRole ? orgRole.value : 'member',
      projects: props.projects
        .filter((project) => grants[project.id]?.selected)
        .map((project) => ({ id: project.id, role: grants[project.id]?.role ?? 'developer' })),
    },
    {
      preserveScroll: true,
      onSuccess: () => {
        emails.value = '';
        open.value = false;
      },
      onFinish: () => (busy.value = false),
    },
  );
}
</script>

<template>
  <Dialog
    v-model:open="open"
    title="Invite people"
    description="They get an email with a link that works for 7 days."
  >
    <form class="flex flex-col gap-4" novalidate data-test="invite-form" @submit.prevent="send">
      <Field
        v-slot="{ id, describedBy, invalid: isInvalid }"
        label="Email addresses"
        help="Separate them with commas or new lines."
        :error="errors?.emails ?? (overLimit ? 'Invite up to 20 people at a time.' : undefined)"
      >
        <Textarea
          :id="id"
          v-model="emails"
          :invalid="isInvalid"
          :described-by="describedBy"
          data-test="invite-emails"
        />
      </Field>
      <p class="text-muted text-xs" data-test="invite-count">
        {{ summary }}
        <span v-if="invalid.length" class="text-danger-text" data-test="invite-invalid"
          >Not valid: {{ invalid.join(', ') }}</span
        >
      </p>

      <label v-if="canChooseOrgRole" class="flex flex-col gap-1 text-sm">
        <span class="font-medium">Organization role</span>
        <select
          v-model="orgRole"
          class="border-control-border bg-surface h-9 rounded-md border px-2"
          data-test="invite-org-role"
        >
          <option value="member">Member</option>
          <option value="owner">Org Owner</option>
        </select>
      </label>

      <fieldset v-if="projects.length" class="flex flex-col gap-2">
        <legend class="text-sm font-medium">Projects</legend>
        <div
          v-for="project in projects"
          :key="project.id"
          class="flex items-center justify-between gap-2"
          data-test="invite-project"
        >
          <label class="flex items-center gap-2 text-sm">
            <input
              v-model="grants[project.id]!.selected"
              type="checkbox"
              :disabled="project.id === fixedProjectId"
            />
            {{ project.name }}
          </label>
          <select
            v-model="grants[project.id]!.role"
            class="border-control-border bg-surface h-8 rounded-md border px-2 text-sm"
            :aria-label="`Role in ${project.name}`"
            :disabled="!grants[project.id]?.selected"
          >
            <option v-for="role in roles" :key="role" :value="role">{{ role }}</option>
          </select>
        </div>
        <p v-if="errors?.projects" class="text-danger-text text-xs" role="alert">
          {{ errors.projects }}
        </p>
      </fieldset>

      <div class="flex justify-end gap-2">
        <Button variant="ghost" @click="open = false">Cancel</Button>
        <Button
          type="submit"
          variant="primary"
          :loading="busy"
          :disabled="count === 0 || overLimit"
          data-test="invite-send"
        >
          Send invitations
        </Button>
      </div>
    </form>
  </Dialog>
</template>
