<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import Button from '../../components/ui/Button.vue';

export interface SessionRow {
  ref: string;
  device: string;
  location: string;
  lastActive: number;
  startedAt: number | null;
  current: boolean;
}

export interface TrustedBrowserRow {
  id: string;
  device: string;
  trustedAt: number | null;
  lastUsedAt: number | null;
  expiresAt: number;
}

defineProps<{ sessions: SessionRow[]; trustedBrowsers: TrustedBrowserRow[] }>();

const format = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

function when(seconds: number | null): string {
  return seconds === null ? 'never' : format.format(new Date(seconds * 1000));
}

function endSession(ref: string): void {
  router.delete(`/account/sessions/${ref}`, { preserveScroll: true });
}

function endOthers(): void {
  router.delete('/account/sessions', { preserveScroll: true });
}

function untrust(id: string): void {
  router.delete(`/account/trusted-browsers/${id}`, { preserveScroll: true });
}
</script>

<template>
  <div class="flex flex-col gap-8">
    <section
      class="flex flex-col gap-3"
      aria-labelledby="sessions-heading"
      data-test="sessions-panel"
    >
      <div class="flex items-start justify-between gap-4">
        <div>
          <h2 id="sessions-heading" class="font-heading text-lg">Your sessions</h2>
          <p class="text-muted text-sm">Where you are signed in. End any you do not recognize.</p>
        </div>
        <Button
          v-if="sessions.length > 1"
          size="sm"
          variant="destructive"
          data-test="sessions-end-others"
          @click="endOthers"
        >
          Sign out everywhere else
        </Button>
      </div>
      <ul class="border-divider divide-divider divide-y rounded-md border">
        <li
          v-for="session in sessions"
          :key="session.ref"
          class="flex items-center justify-between gap-4 p-3"
          :data-test="session.current ? 'session-current' : 'session-row'"
        >
          <div>
            <p class="text-sm font-medium">
              {{ session.device }}
              <span v-if="session.current" class="text-muted font-normal" data-test="session-this">
                · This device
              </span>
            </p>
            <p class="text-muted text-xs">
              {{ session.location }} · Last active {{ when(session.lastActive) }}
            </p>
          </div>
          <Button
            v-if="!session.current"
            size="sm"
            data-test="session-end"
            @click="endSession(session.ref)"
          >
            Sign out
          </Button>
        </li>
      </ul>
    </section>

    <section
      class="flex flex-col gap-3"
      aria-labelledby="trusted-heading"
      data-test="trusted-panel"
    >
      <div>
        <h2 id="trusted-heading" class="font-heading text-lg">Trusted browsers</h2>
        <p class="text-muted text-sm">These skip the two-factor code for 30 days.</p>
      </div>
      <p v-if="trustedBrowsers.length === 0" class="text-muted text-sm" data-test="trusted-empty">
        No browser is trusted.
      </p>
      <ul v-else class="border-divider divide-divider divide-y rounded-md border">
        <li
          v-for="browser in trustedBrowsers"
          :key="browser.id"
          class="flex items-center justify-between gap-4 p-3"
          data-test="trusted-row"
        >
          <div>
            <p class="text-sm font-medium">{{ browser.device }}</p>
            <p class="text-muted text-xs">
              Trusted {{ when(browser.trustedAt) }} · Expires {{ when(browser.expiresAt) }}
            </p>
          </div>
          <Button size="sm" data-test="trusted-revoke" @click="untrust(browser.id)">Remove</Button>
        </li>
      </ul>
    </section>
  </div>
</template>
