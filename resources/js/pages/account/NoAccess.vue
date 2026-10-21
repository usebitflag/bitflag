<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import EmptyState from '../../components/ui/EmptyState.vue';
import AuthLayout from '../../layouts/AuthLayout.vue';
</script>

<template>
  <Head title="No access" />
  <AuthLayout
    title="No projects yet"
    subtitle="You are signed in, but you have not been added to a project."
  >
    <EmptyState
      title="Ask an owner to add you"
      message="Once someone adds you to a project it appears here and in the project switcher."
    >
      <template #actions>
        <button
          type="button"
          class="border-control-border h-8 rounded-md border px-3 text-sm"
          data-test="no-access-signout"
          @click="router.post('/signout')"
        >
          Sign out
        </button>
      </template>
    </EmptyState>
  </AuthLayout>
</template>
