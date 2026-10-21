<script setup lang="ts">
// A pole and six bits; the last bit is drawn as an outline. Colors come from the theme.
</script>

<template>
  <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" data-test="brand-mark">
    <defs>
      <linearGradient id="bf-brand-gradient" x1="0" x2="1" y1="0" y2="1">
        <stop offset="0" stop-color="var(--bf-primary)" />
        <stop offset="1" stop-color="var(--bf-primary-ink)" />
      </linearGradient>
    </defs>
    <rect x="3" y="2" width="2" height="20" rx="1" fill="var(--bf-text)" />
    <rect x="7" y="3" width="4" height="3" rx="1" fill="url(#bf-brand-gradient)" />
    <rect x="13" y="3" width="4" height="3" rx="1" fill="url(#bf-brand-gradient)" />
    <rect x="7" y="9" width="4" height="3" rx="1" fill="url(#bf-brand-gradient)" />
    <rect x="13" y="9" width="4" height="3" rx="1" fill="url(#bf-brand-gradient)" />
    <rect x="7" y="15" width="4" height="3" rx="1" fill="url(#bf-brand-gradient)" />
    <rect
      x="13"
      y="15"
      width="4"
      height="3"
      rx="1"
      fill="none"
      stroke="var(--bf-primary)"
      stroke-width="1.5"
    />
  </svg>
</template>
